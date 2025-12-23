# Code Review: RuleTransformer.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/JsonSchema/RuleTransformer.php`
**Purpose:** Transforms Laravel validation rules into JSON Schema constraints for API documentation and client-side validation.

## Executive Summary
This file contains a massive 887-line static class that converts Laravel validation rules to JSON Schema. While functionally complete, it suffers from severe violations of SOLID principles, particularly Single Responsibility Principle (SRP). The monolithic structure makes it extremely difficult to maintain, test, and extend.

---

## SOLID Principles Analysis

### 🔴 Critical: Single Responsibility Principle Violation
The `RuleTransformer` class violates SRP catastrophically. It has **80+ distinct responsibilities** - one for each Laravel validation rule it transforms. This is acknowledged in line 35 with a TODO comment but never addressed.

**Impact:**
- 850+ lines in a single method
- Impossible to unit test individual rule transformations
- High risk of regression when modifying any rule
- Difficult to add new rules without introducing bugs

### 🟡 Open/Closed Principle Concerns
Adding new validation rules requires modifying the core `transform()` method, violating OCP. There's no extension mechanism for custom rules.

### 🟢 Liskov Substitution Principle
Not applicable - no inheritance hierarchy.

### 🟢 Interface Segregation Principle
Not applicable - no interfaces implemented.

### 🟡 Dependency Inversion Principle
The class has a hard dependency on `Symfony\Component\Intl\Timezones` (line 13, 852), coupling it to a specific implementation.

---

## Code Quality Issues with Exact Line Numbers

### 1. 🔴 Critical: Massive Method Complexity
**Lines:** 63-886
**Issue:** The `transform()` method is 823 lines long with cyclomatic complexity >100.

**Impact:** Untestable, unmaintainable, high bug risk.

**Solution:**
```php
// BEFORE (lines 63-886): One massive method

// AFTER: Extract to individual rule transformer classes
<?php
namespace Cline\Forrst\JsonSchema\Rules;

interface RuleTransformerInterface
{
    public function supports(string $rule): bool;
    public function transform(string $field, string $rule, array &$schema): void;
}

class EmailRuleTransformer implements RuleTransformerInterface
{
    public function supports(string $rule): bool
    {
        return $rule === 'email';
    }

    public function transform(string $field, string $rule, array &$schema): void
    {
        $schema['type'] = 'string';
        $schema['format'] = 'email';
    }
}

class RequiredRuleTransformer implements RuleTransformerInterface
{
    public function supports(string $rule): bool
    {
        return $rule === 'required';
    }

    public function transform(string $field, string $rule, array &$schema): void
    {
        $schema['required'][] = $field;
    }
}

// In RuleTransformer.php (refactored):
final class RuleTransformer
{
    /** @var array<RuleTransformerInterface> */
    private array $transformers = [];

    public function __construct(array $transformers = [])
    {
        $this->transformers = $transformers ?: $this->getDefaultTransformers();
    }

    public function transform(string $field, array $rules): array
    {
        $fieldSchema = [];

        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                if (!method_exists($rule, '__toString')) {
                    continue;
                }
                $rule = (string) $rule;
            }

            foreach ($this->transformers as $transformer) {
                if ($transformer->supports($rule)) {
                    $transformer->transform($field, $rule, $fieldSchema);
                    break;
                }
            }
        }

        return $fieldSchema;
    }

    private function getDefaultTransformers(): array
    {
        return [
            new EmailRuleTransformer(),
            new RequiredRuleTransformer(),
            new BetweenRuleTransformer(),
            // ... register all 80+ rule transformers
        ];
    }
}
```

### 2. 🔴 Critical: Inconsistent Schema Structure
**Lines:** Various
**Issue:** Some rules modify `$fieldSchema` directly (line 78), others create nested `properties[$field]` structures (line 85-94). This inconsistency leads to unpredictable schema output.

**Example of inconsistency:**
```php
// Line 78: Direct modification
$fieldSchema['enum'] = [true, 'true', 1, '1', 'yes', 'on'];

// Lines 85-94: Nested structure
$fieldSchema['properties'][$field] = [
    'if' => [
        'properties' => [
            $otherField => ['const' => $value],
        ],
    ],
    'then' => [
        'required' => [$field],
    ],
];
```

**Solution:**
```php
// Standardize to always use consistent top-level structure
// Create helper methods for common patterns:

private function setSimpleConstraint(array &$schema, string $key, mixed $value): void
{
    $schema[$key] = $value;
}

private function setConditionalConstraint(
    array &$schema,
    string $field,
    array $condition,
    array $consequence
): void {
    $schema['allOf'][] = [
        'if' => $condition,
        'then' => $consequence,
    ];
}
```

### 3. 🟡 Major: No Input Validation
**Lines:** 63-74
**Issue:** The method doesn't validate that `$rules` array contains valid rule formats. Invalid input could cause runtime errors deep in the transformation logic.

**Solution:**
```php
// At start of transform() method (after line 63):
public static function transform(string $field, array $rules): array
{
    if (trim($field) === '') {
        throw new \InvalidArgumentException('Field name cannot be empty');
    }

    foreach ($rules as $rule) {
        if (!is_string($rule) && !is_object($rule)) {
            throw new \InvalidArgumentException(
                sprintf('Rule must be string or object, %s given', get_debug_type($rule))
            );
        }
    }

    $fieldSchema = [];
    // ... rest of method
}
```

### 4. 🟡 Major: Silent Rule Skipping
**Lines:** 68-74
**Issue:** If a rule object doesn't have `__toString()`, it's silently skipped without logging or notification.

**Solution:**
```php
// Replace lines 68-74:
foreach ($rules as $rule) {
    if (!is_string($rule)) {
        if (!method_exists($rule, '__toString')) {
            Log::warning('Skipping rule without __toString method', [
                'rule_class' => get_class($rule),
                'field' => $field,
            ]);
            continue;
        }

        $rule = (string) $rule;
    }

    // ... rest of processing
}
```

### 5. 🟡 Major: Duplicate Code Patterns
**Lines:** 104-108, 111-115, 146-150, 152-157 (and many more)
**Issue:** Nearly identical code blocks for handling date comparisons are copy-pasted with minor variations.

**Example duplication:**
```php
// Lines 104-108 (after:)
if (str_starts_with($rule, 'after:')) {
    $fieldSchema['properties'][$field]['type'] = 'string';
    $fieldSchema['properties'][$field]['format'] = 'date-time';
    $fieldSchema['properties'][$field]['exclusiveMinimum'] = mb_substr($rule, 6);
}

// Lines 111-115 (after_or_equal:) - almost identical
if (str_starts_with($rule, 'after_or_equal:')) {
    $fieldSchema['properties'][$field]['type'] = 'string';
    $fieldSchema['properties'][$field]['format'] = 'date-time';
    $fieldSchema['properties'][$field]['minimum'] = mb_substr($rule, 15);
}
```

**Solution:**
```php
// Extract to parameterized helper method:
private function applyDateComparison(
    array &$schema,
    string $field,
    string $rule,
    string $prefix,
    string $operator
): void {
    $schema['properties'][$field]['type'] = 'string';
    $schema['properties'][$field]['format'] = 'date-time';
    $schema['properties'][$field][$operator] = mb_substr($rule, strlen($prefix));
}

// Usage:
if (str_starts_with($rule, 'after:')) {
    $this->applyDateComparison($fieldSchema, $field, $rule, 'after:', 'exclusiveMinimum');
}

if (str_starts_with($rule, 'after_or_equal:')) {
    $this->applyDateComparison($fieldSchema, $field, $rule, 'after_or_equal:', 'minimum');
}
```

### 6. 🟠 Performance: Repeated String Operations
**Lines:** Multiple (83, 104, 111, 146, etc.)
**Issue:** `mb_substr()` and `str_starts_with()` are called repeatedly without caching results.

**Solution:**
```php
// Cache parsed rule components:
foreach ($rules as $rule) {
    // ... string conversion code ...

    // Parse rule into components once
    $ruleParts = $this->parseRule($rule);
    $ruleName = $ruleParts['name'];
    $ruleParams = $ruleParts['params'];

    // Then use parsed components:
    match ($ruleName) {
        'accepted_if' => $this->handleAcceptedIf($fieldSchema, $field, $ruleParams),
        'after' => $this->handleAfter($fieldSchema, $field, $ruleParams),
        // ... etc
    };
}

private function parseRule(string $rule): array
{
    if (str_contains($rule, ':')) {
        [$name, $params] = explode(':', $rule, 2);
        return ['name' => $name, 'params' => $params];
    }

    return ['name' => $rule, 'params' => null];
}
```

---

## Security Vulnerabilities

### 1. 🔴 Critical: Regular Expression Injection
**Lines:** 260, 265, 276, 738, 841
**Issue:** User input is used directly in `preg_quote()` without additional validation. Malicious rules could cause ReDoS (Regular Expression Denial of Service).

**Example:**
```php
// Line 260:
$fieldSchema['pattern'] = '^(?!'.implode('|', array_map(preg_quote(...), explode(',', mb_substr($rule, 18)))).')';
```

**Solution:**
```php
// Add pattern validation and complexity limits:
private function buildSafePattern(string $input, int $maxItems = 100): string
{
    $items = explode(',', $input);

    if (count($items) > $maxItems) {
        throw JsonSchemaException::create('Too many pattern items (max: ' . $maxItems . ')');
    }

    $quotedItems = array_map(function (string $item): string {
        // Limit item length to prevent ReDoS
        if (strlen($item) > 255) {
            throw JsonSchemaException::create('Pattern item too long (max: 255 chars)');
        }

        return preg_quote($item, '/');
    }, $items);

    return '^(?!' . implode('|', $quotedItems) . ')';
}

// Usage:
if (str_starts_with($rule, 'doesnt_start_with:')) {
    $pattern = $this->buildSafePattern(mb_substr($rule, 18));
    $fieldSchema['pattern'] = $pattern;
}
```

### 2. 🟡 Moderate: No Timezone Validation
**Line:** 852
**Issue:** `Timezones::getIds()` returns all timezones, which could be a large array. No validation that the timezone enum is reasonable in size.

**Solution:**
```php
// Add size validation:
if ($rule === 'timezone') {
    $fieldSchema['type'] = 'string';
    $timezones = Timezones::getIds();

    // Validate reasonable size
    if (count($timezones) > 500) {
        Log::warning('Large timezone enum generated', ['count' => count($timezones)]);
    }

    $fieldSchema['enum'] = $timezones;
}
```

---

## Performance Concerns

### 1. 🟡 O(n²) Complexity in Rule Processing
**Lines:** 67-883
**Issue:** The foreach loop contains multiple `str_starts_with()` checks that could be optimized.

**Solution:** Use a match expression or strategy pattern for O(1) lookup.

### 2. 🟠 Memory: Large Timezone Array
**Line:** 852
**Issue:** Loading all timezones into memory for every timezone rule.

**Solution:** Cache the timezone array at class level:
```php
private static ?array $cachedTimezones = null;

private static function getTimezones(): array
{
    if (self::$cachedTimezones === null) {
        self::$cachedTimezones = Timezones::getIds();
    }
    return self::$cachedTimezones;
}
```

---

## Recommendations

### Immediate Actions (Critical)
1. **Refactor to Strategy Pattern:** Extract each rule transformation into its own class implementing `RuleTransformerInterface`. This resolves the massive SRP violation.
2. **Add Input Validation:** Validate field names and rule formats at method entry.
3. **Fix ReDoS Vulnerability:** Add pattern complexity limits and validation.

### Short-term Improvements (Major)
4. **Standardize Schema Structure:** Create helper methods for consistent schema manipulation.
5. **Add Comprehensive Tests:** With 80+ rules, test coverage is critical. Extract classes to enable proper unit testing.
6. **Add Logging:** Log skipped rules and transformation errors.

### Long-term Enhancements (Minor)
7. **Performance Optimization:** Implement rule parsing cache and timezone caching.
8. **Documentation:** Each rule transformer class should have examples of input/output.
9. **Extensibility:** Allow registration of custom rule transformers via configuration.

---

## Severity Summary
- **Critical Issues:** 3 (Massive method, inconsistent structure, ReDoS vulnerability)
- **Major Issues:** 3 (No validation, silent skipping, duplication)
- **Minor Issues:** 2 (Performance, memory)

**Overall Assessment:** This file requires significant refactoring before any new features are added. The technical debt is substantial and poses maintenance and security risks.

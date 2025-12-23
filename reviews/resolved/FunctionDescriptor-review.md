# Code Review: FunctionDescriptor.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/FunctionDescriptor.php`
**Reviewed:** 2025-12-23  
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

FunctionDescriptor is a sophisticated fluent builder for creating function discovery descriptors. The class demonstrates excellent builder pattern implementation with comprehensive functionality. However, critical issues exist around required property validation, no enforcement of URN format, and missing validation for semantic version strings.

**Overall Assessment:** 🟠 Major Issues
**Recommendation:** Add property requirement validation and URN format enforcement

---

## SOLID Principles Analysis

### Single Responsibility ✅ PASS
Clear responsibility: building FunctionDescriptorData objects with fluent API.

### Open/Closed ✅ PASS
Final class but extensible through method chaining and composition.

### Liskov Substitution ✅ PASS
No inheritance hierarchy.

### Interface Segregation ✅ PASS
Fluent interface provides cohesive, focused API.

### Dependency Inversion ✅ PASS
Depends on Data abstractions (ArgumentData, ResultDescriptorData, etc.).

---

## Code Quality Issues

### 🔴 CRITICAL: No Validation for Required Properties (Lines 405-510)

**Issue:** The builder allows creating incomplete function descriptors. The `urn` and `summary` properties are never set but getters return uninitialized values causing runtime errors.

**Location:** Lines 74-101, 405-418

**Impact:**
- Runtime errors when getters called on incomplete builders
- Invalid discovery documents generated
- Difficult debugging of missing properties

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/FunctionDescriptor.php

// Add validation method after getExtensions() (around line 510):

/**
 * Validate builder has all required properties before exporting.
 *
 * @throws \RuntimeException
 */
public function validate(): self
{
    $errors = [];

    if (!isset($this->urn)) {
        $errors[] = 'URN is required. Call urn() to set function URN.';
    }

    if (!isset($this->summary)) {
        $errors[] = 'Summary is required. Call summary() to set function description.';
    }

    if (empty($errors)) {
        return $this;
    }

    throw new \RuntimeException(
        'FunctionDescriptor incomplete. Missing required fields:' . PHP_EOL .
        '- ' . implode(PHP_EOL . '- ', $errors)
    );
}

// Update getters to validate (lines 405-418):
public function getUrn(): string
{
    if (!isset($this->urn)) {
        throw new \RuntimeException(
            'URN not set. Call urn() before building function descriptor.'
        );
    }

    return $this->urn;
}

public function getSummary(): string
{
    if (!isset($this->summary)) {
        throw new \RuntimeException(
            'Summary not set. Call summary() before building function descriptor.'
        );
    }

    return $this->summary;
}

// Add toData() method for safe export:
/**
 * Export to FunctionDescriptorData with validation.
 *
 * @throws \RuntimeException If required fields missing
 */
public function toData(): FunctionDescriptorData
{
    $this->validate();

    return new FunctionDescriptorData(
        name: $this->urn,
        version: $this->version,
        arguments: $this->arguments,
        stability: $this->getStability(),
        summary: $this->summary,
        description: $this->description,
        tags: $this->tags,
        result: $this->result,
        errors: $this->errors,
        query: $this->query,
        deprecated: $this->deprecated,
        sideEffects: $this->sideEffects,
        discoverable: $this->discoverable,
        examples: $this->examples,
        simulations: $this->simulations,
        links: $this->links,
        externalDocs: $this->externalDocs,
        extensions: $this->extensions,
    );
}

/**
 * Get stability from version prerelease identifier.
 */
private function getStability(): ?string
{
    if (str_contains($this->version, '-alpha')) {
        return 'alpha';
    }

    if (str_contains($this->version, '-beta')) {
        return 'beta';
    }

    if (str_contains($this->version, '-rc')) {
        return 'rc';
    }

    return 'stable';
}
```

### 🔴 CRITICAL: No URN Format Validation (Line 84)

**Issue:** The `urn()` method accepts any string without validating URN format.

**Location:** Lines 84-89

**Impact:**
- Invalid URNs break function routing
- Discovery documents fail validation
- Client integration errors

**Solution:**
```php
// Replace urn() method (lines 84-89):
/**
 * Set the function URN.
 *
 * @param BackedEnum|string $urn Function URN (e.g., "urn:acme:forrst:fn:orders:create")
 * @throws \InvalidArgumentException If URN format invalid
 */
public function urn(BackedEnum|string $urn): self
{
    $urnString = $urn instanceof BackedEnum ? (string) $urn->value : $urn;

    // Validate URN format: urn:namespace:forrst:fn:function:name
    if (!preg_match('/^urn:[a-z][a-z0-9-]*:forrst:fn:[a-z][a-z0-9:.]*$/i', $urnString)) {
        throw new \InvalidArgumentException(
            "Invalid function URN format: '{$urnString}'. " .
            "Expected format: 'urn:namespace:forrst:fn:function:name' " .
            "(e.g., 'urn:acme:forrst:fn:users:get')"
        );
    }

    $this->urn = $urnString;

    return $this;
}
```

### 🟠 MAJOR: No Semantic Version Validation (Line 96)

**Issue:** The `version()` method accepts any string without validating semantic versioning format.

**Location:** Lines 96-101

**Impact:**
- Invalid versions break client compatibility checks
- Sorting and comparison logic fails
- Poor version management

**Solution:**
```php
// Replace version() method (lines 96-101):
/**
 * Set the function version.
 *
 * @param string $version Semantic version (e.g., "1.0.0", "2.0.0-beta.1")
 * @throws \InvalidArgumentException If version invalid
 */
public function version(string $version): self
{
    // Validate semantic versioning format
    $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
        '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)' .
        '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'  .
        '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    if (!preg_match($semverPattern, $version)) {
        throw new \InvalidArgumentException(
            "Invalid semantic version: '{$version}'. " .
            "Must follow semver format (e.g., '1.0.0', '2.1.0-beta.1', '3.0.0+build.123'). " .
            "See: https://semver.org/"
        );
    }

    $this->version = $version;

    return $this;
}
```

### 🟠 MAJOR: Schema Parameter Type Not Documented (Line 151)

**Issue:** The `$schema` parameter in `argument()` accepts any array without type information or validation.

**Location:** Lines 149-171

**Impact:**
- Invalid JSON Schemas pass undetected
- Runtime errors during validation
- Poor developer experience

**Solution:**
```php
// Update argument() method with schema validation:
public function argument(
    string $name,
    array $schema = ['type' => 'string'],
    bool $required = false,
    ?string $summary = null,
    ?string $description = null,
    mixed $default = null,
    ?DeprecatedData $deprecated = null,
    ?array $examples = null,
): self {
    // Validate schema structure
    $this->validateJsonSchema($schema);

    $this->arguments[] = new ArgumentData(
        name: $name,
        schema: $schema,
        required: $required,
        summary: $summary,
        description: $description,
        default: $default,
        deprecated: $deprecated,
        examples: $examples,
    );

    return $this;
}

/**
 * Validate JSON Schema structure.
 *
 * @param array<string, mixed> $schema
 * @throws \InvalidArgumentException
 */
private function validateJsonSchema(array $schema): void
{
    if (!isset($schema['type']) && !isset($schema['$ref'])) {
        throw new \InvalidArgumentException(
            'JSON Schema must include "type" or "$ref" property'
        );
    }

    if (isset($schema['type'])) {
        $validTypes = ['null', 'boolean', 'object', 'array', 'number', 'string', 'integer'];

        if (!in_array($schema['type'], $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid JSON Schema type: '{$schema['type']}'. " .
                'Must be one of: ' . implode(', ', $validTypes)
            );
        }
    }
}
```

### 🟡 MINOR: Side Effects Array Not Validated (Line 330)

**Issue:** The `sideEffects()` method accepts any array without validating values are standard effect types.

**Location:** Lines 330-335

**Impact:**
- Invalid side effect types in discovery documents
- Clients cannot rely on standard values

**Solution:**
```php
// Replace sideEffects() method:
/**
 * Set side effects this function may cause.
 *
 * @param array<int, string> $effects Side effect types
 * @throws \InvalidArgumentException
 */
public function sideEffects(array $effects): self
{
    $validEffects = ['create', 'update', 'delete', 'read'];

    foreach ($effects as $effect) {
        if (!in_array($effect, $validEffects, true)) {
            throw new \InvalidArgumentException(
                "Invalid side effect: '{$effect}'. " .
                'Must be one of: ' . implode(', ', $validEffects)
            );
        }
    }

    $this->sideEffects = $effects;

    return $this;
}
```

---

## Architectural Recommendations

### 🔵 SUGGESTION: Add Named Constructors for Common Patterns

```php
/**
 * Create a simple query function (GET-style, no side effects).
 */
public static function query(string $urn, string $summary): self
{
    return self::make()
        ->urn($urn)
        ->summary($summary)
        ->sideEffects([]);
}

/**
 * Create a mutation function (POST/PUT/DELETE-style).
 */
public static function mutation(string $urn, string $summary, array $sideEffects): self
{
    return self::make()
        ->urn($urn)
        ->summary($summary)
        ->sideEffects($sideEffects);
}

/**
 * Create a list function with query capabilities.
 */
public static function list(string $urn, string $summary, QueryCapabilitiesData $query): self
{
    return self::make()
        ->urn($urn)
        ->summary($summary)
        ->query($query)
        ->resultResource('collection', collection: true);
}
```

---

## Test Coverage Recommendations

```php
use Cline\Forrst\Discovery\FunctionDescriptor;

describe('FunctionDescriptor', function () {
    it('builds complete function descriptor', function () {
        $descriptor = FunctionDescriptor::make()
            ->urn('urn:acme:forrst:fn:users:get')
            ->version('1.0.0')
            ->summary('Get user by ID')
            ->description('Retrieves a user by their unique identifier')
            ->argument('userId', ['type' => 'integer'], required: true)
            ->resultResource('user')
            ->error('USER_NOT_FOUND', 'User not found')
            ->validate();

        expect($descriptor->getUrn())->toBe('urn:acme:forrst:fn:users:get')
            ->and($descriptor->getVersion())->toBe('1.0.0');
    });

    it('rejects invalid URN format', function () {
        expect(fn() => FunctionDescriptor::make()
            ->urn('invalid-urn')
        )->toThrow(InvalidArgumentException::class, 'Invalid function URN');
    });

    it('rejects invalid semantic version', function () {
        expect(fn() => FunctionDescriptor::make()
            ->version('v1.0')
        )->toThrow(InvalidArgumentException::class, 'Invalid semantic version');
    });

    it('throws when building without required URN', function () {
        expect(fn() => FunctionDescriptor::make()
            ->summary('Test')
            ->getUrn()
        )->toThrow(RuntimeException::class, 'URN not set');
    });

    it('validates JSON Schema in arguments', function () {
        expect(fn() => FunctionDescriptor::make()
            ->argument('param', ['invalid' => 'schema'])
        )->toThrow(InvalidArgumentException::class, 'must include "type"');
    });

    it('rejects invalid side effects', function () {
        expect(fn() => FunctionDescriptor::make()
            ->sideEffects(['invalid-effect'])
        )->toThrow(InvalidArgumentException::class, 'Invalid side effect');
    });
});
```

---

## Summary

### Critical Issues
1. 🔴 Add required property validation (urn, summary)
2. 🔴 Validate URN format
3. 🔴 Add toData() export method with validation

### Major Issues
4. 🟠 Validate semantic versioning
5. 🟠 Validate JSON Schema in arguments

### Minor Issues
6. 🟡 Validate side effects array values
7. 🟡 Add named constructors for common patterns

### Estimated Effort: 4-6 hours

Excellent builder pattern implementation needing validation layer to prevent invalid state.

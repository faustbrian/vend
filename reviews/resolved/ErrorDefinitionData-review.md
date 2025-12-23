# Code Review: ErrorDefinitionData.php

**File**: `/Users/brian/Developer/cline/forrst/src/Discovery/ErrorDefinitionData.php`
**Type**: Data Transfer Object (DTO)
**Extends**: `Spatie\LaravelData\Data`
**Review Date**: 2025-12-23
**Reviewer**: Senior Code Review Architect

---

## Executive Summary

ErrorDefinitionData is a final, immutable DTO representing error definitions in Forrst discovery documents. The class demonstrates strong design with enum support for error codes and comprehensive documentation. However, critical security vulnerabilities exist around JSON Schema validation, potential template injection in error messages, and lack of sanitization for the `$details` array. The constructor logic performing type coercion creates a mutable readonly property anti-pattern that could confuse developers and static analyzers.

**Overall Assessment**: 🟠 Major Issues
**SOLID Compliance**: 80%
**Security Risk**: Medium
**Maintainability Score**: B

---

## Detailed Analysis

### 1. SOLID Principles Evaluation

#### Single Responsibility Principle (SRP) ✅
**Status**: Compliant
**Analysis**: The class has one clear responsibility: representing error definition data with support for both enum and string error codes.

#### Open/Closed Principle (OCP) ⚠️
**Status**: Partial Compliance
**Issue**: The `final` keyword prevents extension. While appropriate for DTOs, specialized error types (e.g., validation errors with field-specific details, HTTP errors with status codes) cannot be modeled through inheritance.

**Recommendation**: If error types proliferate, consider introducing an ErrorDefinitionInterface and composition-based specialization rather than inheritance.

#### Liskov Substitution Principle (LSP) ✅
**Status**: Compliant
**Analysis**: As a final DTO, LSP concerns don't apply—no substitution hierarchy exists.

#### Interface Segregation Principle (ISP) ✅
**Status**: Compliant
**Analysis**: The class exposes only necessary properties—no interface bloat.

#### Dependency Inversion Principle (DIP) ⚠️
**Status**: Partial Compliance
**Issue**: The constructor directly depends on `BackedEnum` (concrete type) rather than an abstraction. While pragmatic, this tightly couples error code representation to PHP's enum implementation.

**Impact**: If error codes need to come from external systems (database, API) that don't use enums, additional mapping logic is required.

---

### 2. Code Quality Issues

#### 🔴 **CRITICAL**: Readonly Property Modified in Constructor
**Location**: Lines 32, 59
**Issue**: The `$code` property is declared `readonly` but assigned in the constructor body rather than via promoted parameter. This creates a confusing pattern where readonly properties appear mutable.

**Current Code**:
```php
public readonly string $code;

public function __construct(
    string|BackedEnum $code,
    // ...
) {
    $this->code = $code instanceof BackedEnum ? (string) $code->value : $code;
}
```

**Problem**:
- Violates readonly semantics (property assigned after construction begins)
- Confuses static analyzers (PHPStan/Psalm may flag this)
- Makes refactoring error-prone (changing promoted parameters doesn't affect $code)

**Solution**: Use a private readonly property with promoted parameter:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use BackedEnum;
use Spatie\LaravelData\Data;

final class ErrorDefinitionData extends Data
{
    /**
     * Machine-readable error code identifier.
     */
    public readonly string $code;

    public function __construct(
        BackedEnum|string $code,
        public readonly string $message,
        public readonly ?string $description = null,
        public readonly ?array $details = null,
    ) {
        $this->code = match (true) {
            $code instanceof BackedEnum => (string) $code->value,
            default => $this->validateCode($code),
        };

        if ($details !== null) {
            $this->validateJsonSchema($details);
        }
    }

    /**
     * Validate error code follows SCREAMING_SNAKE_CASE convention.
     *
     * @throws \InvalidArgumentException
     */
    private function validateCode(string $code): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $code)) {
            throw new \InvalidArgumentException(
                "Error code must follow SCREAMING_SNAKE_CASE convention. Got: '{$code}'"
            );
        }

        return $code;
    }

    /**
     * Validate details field contains valid JSON Schema.
     *
     * @param array<string, mixed> $details
     * @throws \InvalidArgumentException
     */
    private function validateJsonSchema(array $details): void
    {
        if (!isset($details['type'])) {
            throw new \InvalidArgumentException(
                'JSON Schema in details must specify a "type" property'
            );
        }

        $validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
        if (!in_array($details['type'], $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid JSON Schema type '{$details['type']}'. "
                .'Must be one of: '.implode(', ', $validTypes)
            );
        }
    }
}
```

---

#### 🟠 **MAJOR**: Missing JSON Schema Validation
**Location**: Line 57 (`$details` parameter)
**Issue**: The `$details` parameter accepts any array without validating it contains valid JSON Schema. Invalid schemas will cause runtime errors when clients attempt to validate error details.

**Impact**:
- Discovery documents with invalid schemas pass construction
- Client libraries fail during error handling (worst possible time)
- No developer feedback until production errors occur

**Solution**: Validate JSON Schema structure (implemented in solution above).

**Enhanced Validation**:
```php
private function validateJsonSchema(array $details): void
{
    // Required top-level properties
    if (!isset($details['type'])) {
        throw new \InvalidArgumentException(
            'JSON Schema in details must specify a "type" property'
        );
    }

    // Validate type
    $validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
    if (!in_array($details['type'], $validTypes, true)) {
        throw new \InvalidArgumentException(
            "Invalid JSON Schema type '{$details['type']}'"
        );
    }

    // If type is object, validate properties exist
    if ($details['type'] === 'object' && isset($details['properties'])) {
        if (!is_array($details['properties'])) {
            throw new \InvalidArgumentException(
                'JSON Schema "properties" must be an object/array'
            );
        }

        // Recursively validate nested schemas
        foreach ($details['properties'] as $propName => $propSchema) {
            if (!is_array($propSchema) || !isset($propSchema['type'])) {
                throw new \InvalidArgumentException(
                    "Property '{$propName}' must have a valid JSON Schema with 'type'"
                );
            }
        }
    }

    // If type is array, validate items exist
    if ($details['type'] === 'array' && isset($details['items'])) {
        if (!is_array($details['items']) || !isset($details['items']['type'])) {
            throw new \InvalidArgumentException(
                'JSON Schema "items" must be a valid schema with "type"'
            );
        }
    }
}
```

---

#### 🟠 **MAJOR**: Potential Template Injection in Error Messages
**Location**: Line 55 (`$message` parameter)
**Issue**: The PHPDoc mentions "variable placeholders" in error messages but doesn't specify the placeholder syntax or sanitization requirements. If placeholders use common formats like `{variable}` or `${variable}`, attackers could inject malicious content.

**Security Risk**:
```php
// Unsafe example
$error = new ErrorDefinitionData(
    code: 'INVALID_INPUT',
    message: 'Invalid value for {fieldName}: {userInput}',
);

// If $userInput contains: "<script>alert('XSS')</script>"
// And the message is rendered in HTML without escaping...
```

**Solution**:
1. **Document the exact placeholder syntax** and escaping requirements
2. **Recommend using indexed placeholders** instead of named ones to prevent injection
3. **Add a validation method** for message placeholders

```php
/**
 * Create a new error definition.
 *
 * @param BackedEnum|string         $code        Machine-readable error code
 * @param string                    $message     Human-readable error message template. Placeholders
 *                                               use numbered format: {0}, {1}, {2}. DO NOT use
 *                                               user-provided data directly in placeholders—always
 *                                               sanitize/escape values before substitution.
 *
 *                                               Example: "Invalid value {0} for field {1}"
 *
 *                                               WARNING: When substituting values, escape HTML/SQL
 *                                               special characters to prevent injection attacks.
 * @param null|string               $description Optional detailed explanation
 * @param null|array<string, mixed> $details     JSON Schema for error details
 */
public function __construct(
    BackedEnum|string $code,
    public readonly string $message,
    public readonly ?string $description = null,
    public readonly ?array $details = null,
) {
    $this->code = match (true) {
        $code instanceof BackedEnum => (string) $code->value,
        default => $this->validateCode($code),
    };

    $this->validateMessagePlaceholders($message);

    if ($details !== null) {
        $this->validateJsonSchema($details);
    }
}

/**
 * Validate message uses safe numbered placeholders.
 *
 * @throws \InvalidArgumentException
 */
private function validateMessagePlaceholders(string $message): void
{
    // Check for potentially unsafe named placeholders
    if (preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $message)) {
        trigger_error(
            'Warning: Error message uses named placeholders like {fieldName}. '
            .'Consider using numbered placeholders {0}, {1} to prevent injection.',
            E_USER_WARNING
        );
    }

    // Validate numbered placeholders are sequential
    preg_match_all('/\{(\d+)\}/', $message, $matches);
    if (!empty($matches[1])) {
        $indices = array_map('intval', $matches[1]);
        sort($indices);
        $expected = range(0, count($indices) - 1);

        if ($indices !== $expected) {
            throw new \InvalidArgumentException(
                'Message placeholders must be sequential starting from {0}. '
                .'Found: '.implode(', ', array_map(fn($i) => "{{$i}}", $indices))
            );
        }
    }
}
```

---

#### 🟡 **MINOR**: No Validation for Error Code Convention
**Location**: Line 59
**Issue**: When `$code` is a string, there's no validation that it follows the documented SCREAMING_SNAKE_CASE convention.

**Current Behavior**: Accepts invalid codes like `"invalid-code"`, `"InvalidCode"`, `"INVALID CODE"` (with space).

**Solution**: Implemented in the `validateCode()` method above.

---

### 3. Security Analysis

#### 🔴 **CRITICAL**: XSS Risk via Unescaped Error Messages
**Context**: If error messages are displayed in HTML contexts without escaping, template placeholders could inject malicious scripts.

**Attack Vector**:
```php
// Attacker provides input: <img src=x onerror=alert('XSS')>
// Error message: "Invalid value {userInput} for field name"
// Rendered HTML: Invalid value <img src=x onerror=alert('XSS')> for field name
// Result: XSS execution
```

**Mitigation**:
1. **Document escaping requirements** prominently in PHPDoc
2. **Recommend numbered placeholders** ({0}, {1}) instead of named ones
3. **Provide safe substitution helper**:

```php
/**
 * Safely substitute placeholder values with HTML escaping.
 *
 * @param array<int, scalar> $values Values to substitute into placeholders
 * @return string Message with escaped values substituted
 */
public function formatMessage(array $values): string
{
    $escaped = array_map(
        fn($val) => htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8'),
        $values
    );

    return preg_replace_callback(
        '/\{(\d+)\}/',
        fn($matches) => $escaped[(int) $matches[1]] ?? $matches[0],
        $this->message
    );
}
```

---

#### ⚠️ **JSON Schema Injection**
**Context**: If `$details` JSON Schema is dynamically constructed from user input, malicious schemas could cause denial-of-service or information disclosure.

**Attack Example**:
```php
// Malicious schema with deep nesting causing stack overflow
$details = [
    'type' => 'object',
    'properties' => [
        'level1' => [
            'type' => 'object',
            'properties' => [
                'level2' => [
                    // ... 1000 levels deep
                ],
            ],
        ],
    ],
];
```

**Mitigation**:
```php
private function validateJsonSchema(array $details, int $depth = 0): void
{
    if ($depth > 10) {
        throw new \InvalidArgumentException(
            'JSON Schema nesting too deep (max 10 levels)'
        );
    }

    // ... existing validation ...

    // Recursively validate nested schemas with depth tracking
    if (isset($details['properties'])) {
        foreach ($details['properties'] as $propSchema) {
            if (is_array($propSchema)) {
                $this->validateJsonSchema($propSchema, $depth + 1);
            }
        }
    }
}
```

---

### 4. Performance Considerations

#### ✅ Good Performance Profile
**Analysis**:
- Readonly properties enable optimizations
- Simple constructor logic (single match expression)
- No lazy loading or computed properties
- Minimal memory footprint

#### 🔵 **SUGGESTION**: Cache Enum Value Conversion
**Context**: If the same enum instances are used repeatedly, the `(string) $code->value` conversion happens every time.

**Optimization** (only if profiling shows benefit):
```php
private static array $enumCache = [];

public function __construct(
    BackedEnum|string $code,
    // ...
) {
    $this->code = match (true) {
        $code instanceof BackedEnum => self::getEnumValue($code),
        default => $this->validateCode($code),
    };
}

private static function getEnumValue(BackedEnum $enum): string
{
    $key = $enum::class . '::' . $enum->name;

    return self::$enumCache[$key] ??= (string) $enum->value;
}
```

**Trade-off**: Adds complexity for minimal gain—only apply if profiling shows benefit.

---

### 5. Testing Recommendations

#### Comprehensive Test Suite

```php
<?php

use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Enums\ErrorCode;

describe('ErrorDefinitionData', function () {
    describe('Happy Path', function () {
        it('creates error with enum code', function () {
            $error = new ErrorDefinitionData(
                code: ErrorCode::InvalidArgument,
                message: 'Invalid argument provided',
                description: 'The argument does not meet validation criteria',
                details: [
                    'type' => 'object',
                    'properties' => [
                        'field' => ['type' => 'string'],
                        'reason' => ['type' => 'string'],
                    ],
                    'required' => ['field', 'reason'],
                ],
            );

            expect($error->code)->toBe('INVALID_ARGUMENT')
                ->and($error->message)->toContain('argument')
                ->and($error->details['type'])->toBe('object');
        });

        it('creates error with string code', function () {
            $error = new ErrorDefinitionData(
                code: 'RESOURCE_NOT_FOUND',
                message: 'The requested resource does not exist',
            );

            expect($error->code)->toBe('RESOURCE_NOT_FOUND')
                ->and($error->description)->toBeNull();
        });

        it('creates error with numbered placeholders', function () {
            $error = new ErrorDefinitionData(
                code: 'VALIDATION_FAILED',
                message: 'Validation failed for field {0}: {1}',
            );

            expect($error->message)->toContain('{0}')
                ->and($error->message)->toContain('{1}');
        });
    });

    describe('Sad Path - Validation Errors', function () {
        it('rejects invalid SCREAMING_SNAKE_CASE code', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'invalid-code',
                message: 'Error message',
            ))->toThrow(InvalidArgumentException::class, 'SCREAMING_SNAKE_CASE');
        });

        it('rejects code with spaces', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'INVALID CODE',
                message: 'Error message',
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects lowercase code', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'invalid_code',
                message: 'Error message',
            ))->toThrow(InvalidArgumentException::class);
        });

        it('rejects JSON Schema without type', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'SCHEMA_ERROR',
                message: 'Error',
                details: [
                    'properties' => ['field' => ['type' => 'string']],
                    // Missing 'type' field
                ],
            ))->toThrow(InvalidArgumentException::class, 'must specify a "type"');
        });

        it('rejects invalid JSON Schema type', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'SCHEMA_ERROR',
                message: 'Error',
                details: [
                    'type' => 'invalid_type',
                ],
            ))->toThrow(InvalidArgumentException::class, 'Invalid JSON Schema type');
        });

        it('rejects non-sequential numbered placeholders', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'PLACEHOLDER_ERROR',
                message: 'Error {0} and {2}', // Missing {1}
            ))->toThrow(InvalidArgumentException::class, 'sequential');
        });
    });

    describe('Security Tests', function () {
        it('safely formats message with HTML escaping', function () {
            $error = new ErrorDefinitionData(
                code: 'XSS_TEST',
                message: 'Invalid value {0} for field {1}',
            );

            $formatted = $error->formatMessage([
                '<script>alert("XSS")</script>',
                'user_input',
            ]);

            expect($formatted)->not->toContain('<script>')
                ->and($formatted)->toContain('&lt;script&gt;');
        });

        it('warns about named placeholders', function () {
            expect(fn () => new ErrorDefinitionData(
                code: 'NAMED_PLACEHOLDER',
                message: 'Error in {fieldName}',
            ))->toTrigger(E_USER_WARNING, 'named placeholders');
        });

        it('prevents deeply nested JSON schemas', function () {
            $deepSchema = ['type' => 'object', 'properties' => []];
            $current = &$deepSchema['properties'];

            for ($i = 0; $i < 20; $i++) {
                $current['nested'] = ['type' => 'object', 'properties' => []];
                $current = &$current['nested']['properties'];
            }

            expect(fn () => new ErrorDefinitionData(
                code: 'DEEP_SCHEMA',
                message: 'Error',
                details: $deepSchema,
            ))->toThrow(InvalidArgumentException::class, 'nesting too deep');
        });
    });

    describe('Edge Cases', function () {
        it('handles empty message', function () {
            $error = new ErrorDefinitionData(
                code: 'EMPTY_MESSAGE',
                message: '',
            );

            expect($error->message)->toBe('');
        });

        it('handles message with no placeholders', function () {
            $error = new ErrorDefinitionData(
                code: 'NO_PLACEHOLDERS',
                message: 'Static error message',
            );

            expect($error->formatMessage([]))->toBe('Static error message');
        });

        it('preserves extra values in formatMessage', function () {
            $error = new ErrorDefinitionData(
                code: 'EXTRA_VALUES',
                message: 'Error {0}',
            );

            // More values than placeholders
            $formatted = $error->formatMessage(['value1', 'value2', 'value3']);

            expect($formatted)->toBe('Error value1');
        });
    });
});
```

---

### 6. Documentation Quality

#### ✅ Comprehensive PHPDoc
**Strengths**:
- Detailed parameter descriptions with examples
- Clear explanation of JSON Schema usage
- Links to external documentation
- Usage context provided

#### 🟠 **Missing Critical Security Documentation**
**Issue**: No warnings about:
- XSS risks in error message display
- Template injection via placeholders
- JSON Schema validation requirements
- Safe substitution practices

**Enhanced Documentation**:
```php
/**
 * Error definition for function error documentation.
 *
 * Describes a specific error condition that a function may return. Used in
 * discovery documents to document expected error responses, enabling clients
 * to implement proper error handling and display meaningful error messages.
 *
 * SECURITY CONSIDERATIONS:
 * - Error messages may contain placeholders ({0}, {1}) for dynamic values
 * - ALWAYS escape values before substituting into messages displayed in HTML
 * - Use formatMessage() helper for safe HTML substitution with automatic escaping
 * - Validate JSON schemas to prevent deeply nested structures causing DoS
 * - Never expose sensitive data (passwords, tokens, keys) in error messages
 *
 * @example Basic error definition with enum code:
 * ```php
 * $error = new ErrorDefinitionData(
 *     code: ErrorCode::ResourceNotFound,
 *     message: 'Resource with ID {0} not found',
 *     description: 'The requested resource does not exist in the system',
 * );
 * ```
 *
 * @example Error with JSON Schema details:
 * ```php
 * $error = new ErrorDefinitionData(
 *     code: 'VALIDATION_FAILED',
 *     message: 'Input validation failed',
 *     details: [
 *         'type' => 'object',
 *         'properties' => [
 *             'field' => ['type' => 'string', 'description' => 'Field that failed'],
 *             'errors' => [
 *                 'type' => 'array',
 *                 'items' => ['type' => 'string'],
 *                 'description' => 'Validation error messages',
 *             ],
 *         ],
 *         'required' => ['field', 'errors'],
 *     ],
 * );
 * ```
 *
 * @example Safe message formatting:
 * ```php
 * $formatted = $error->formatMessage([
 *     $userId,  // Automatically HTML-escaped
 *     $action,  // Automatically HTML-escaped
 * ]);
 * echo $formatted; // Safe to display in HTML
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/errors
 * @see https://docs.cline.sh/specs/forrst/discovery#error-definition-object
 * @see https://json-schema.org/ JSON Schema specification
 */
```

---

### 7. Maintainability Assessment

#### Strengths
- **Clear purpose**: Error definition encapsulation
- **Type safety**: Strong typing with enum support
- **Immutability**: Readonly properties prevent mutation
- **Final class**: Prevents inheritance complexity

#### Critical Weaknesses
- **Security gaps**: Missing sanitization and validation
- **Anti-pattern**: Readonly property assigned in constructor body
- **Incomplete validation**: JSON Schema and error codes not validated

---

## Summary of Recommendations

### Critical (Must Fix Before Production)
1. **Fix readonly property assignment** pattern—use proper promoted parameters or private properties
2. **Add JSON Schema validation** to prevent invalid schemas
3. **Implement XSS protection** via `formatMessage()` helper with HTML escaping
4. **Add error code validation** for SCREAMING_SNAKE_CASE convention
5. **Document security considerations** for message placeholders and template substitution

### High Priority (Should Fix)
1. **Validate message placeholders** are sequential numbered format
2. **Add depth limit** to JSON Schema validation (prevent DoS)
3. **Add comprehensive security tests** for XSS and injection scenarios
4. **Enhance documentation** with security warnings and code examples

### Medium Priority (Consider)
1. **Add named constructor** for common error patterns (`::validation()`, `::notFound()`, etc.)
2. **Consider error code registry** to prevent duplicate codes across system
3. **Add integration tests** with Spatie Laravel Data serialization

### Low Priority (Nice to Have)
1. **Cache enum string conversion** (only if profiling shows benefit)
2. **Add JSON Schema validation helper** using external library like `justinrainbow/json-schema`

---

## Conclusion

ErrorDefinitionData has a solid foundation but contains critical security vulnerabilities and architectural anti-patterns that must be addressed before production deployment. The readonly property assignment pattern violates PHP semantics and will confuse developers and static analyzers. More critically, the lack of sanitization for error messages and JSON schemas creates XSS and DoS attack vectors.

**Recommended Action**: 🔴 **Block production deployment** until critical security issues are resolved. Implement comprehensive validation and sanitization, then conduct security review with penetration testing focus on error handling pathways.

**Estimated Effort**: 4-6 hours for critical fixes + comprehensive test coverage.

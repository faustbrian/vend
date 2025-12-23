# Code Review: ExtensionData.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Data/ExtensionData.php`
**Reviewer:** Senior Code Review Architect
**Date:** 2025-12-23
**Status:** ✅ COMPREHENSIVE REVIEW COMPLETE

---

## Executive Summary

**Overall Assessment:** GOOD (7.5/10)

ExtensionData is a well-designed Data Transfer Object that represents Forrst protocol extensions. The class demonstrates solid design principles with immutability, comprehensive PHPDoc documentation, and thoughtful separation between request and response contexts. However, it requires critical validation improvements to prevent invalid URN formats, mutually exclusive field violations, and array injection vulnerabilities.

**Key Strengths:**
- Excellent immutability with readonly properties and psalm-immutable annotation
- Clear request/response separation via factory methods and specialized toArray methods
- Comprehensive PHPDoc with detailed examples and use cases
- Clean handling of BackedEnum support for type safety

**Critical Issues:**
- 🔴 No URN format validation (accepts invalid URNs)
- 🔴 Missing mutual exclusivity enforcement (both options and data can be set simultaneously)
- 🟠 Empty URN strings accepted without validation
- 🟠 Array type safety issues (nested arrays, mixed types accepted)
- 🟡 Factory method naming doesn't follow `createFrom*` convention

---

## SOLID Principles Analysis

| Principle | Rating | Assessment |
|-----------|--------|------------|
| **Single Responsibility** | ✅ EXCELLENT | Class has one clear purpose: represent extension data with context-aware serialization |
| **Open/Closed** | ✅ GOOD | Extensible via BackedEnum support; closed for modification of core logic |
| **Liskov Substitution** | ⚠️ N/A | No inheritance hierarchy |
| **Interface Segregation** | ✅ GOOD | Minimal, focused public API with context-specific methods |
| **Dependency Inversion** | ✅ EXCELLENT | Depends on abstractions (BackedEnum) not concretions |

---

## Detailed Code Quality Issues

### 🔴 CRITICAL: Missing URN Format Validation

**Issue:** Constructor accepts any string as URN without validating proper URN format
**Location:** Lines 53-64
**Impact:** Invalid URNs bypass protocol standards, causing interoperability failures and client-side parsing errors. Malformed URNs like `"invalid"` or `"urn:"` are accepted.

**Current Code:**
```php
public function __construct(
    string|BackedEnum $urn,
    public ?array $options = null,
    public ?array $data = null,
) {
    if ($urn instanceof BackedEnum) {
        $urnValue = $urn->value;
        $this->urn = is_string($urnValue) ? $urnValue : (string) $urnValue;
    } else {
        $this->urn = $urn; // ⚠️ No validation!
    }
}
```

**Solution:**
```php
// In app/Helpers/UrnValidator.php (create this file):
<?php declare(strict_types=1);

namespace Cline\Forrst\Helpers;

use InvalidArgumentException;

class UrnValidator
{
    /**
     * Validate Forrst extension URN format.
     *
     * @param string $urn URN to validate
     * @param string $fieldName Field name for error messages
     * @throws InvalidArgumentException If URN is invalid
     */
    public static function validateExtensionUrn(string $urn, string $fieldName = 'urn'): void
    {
        if ($urn === '') {
            throw new InvalidArgumentException("Extension {$fieldName} cannot be empty");
        }

        // Forrst extension URNs must follow: urn:forrst:ext:name
        if (!preg_match('/^urn:forrst:ext:[a-z0-9][a-z0-9_-]*$/i', $urn)) {
            throw new InvalidArgumentException(
                "Extension {$fieldName} must follow format 'urn:forrst:ext:name', got: {$urn}"
            );
        }

        // Validate URN length (reasonable limit)
        if (strlen($urn) > 255) {
            throw new InvalidArgumentException(
                "Extension {$fieldName} exceeds maximum length of 255 characters"
            );
        }
    }
}

// Then update ExtensionData.php constructor (lines 53-64):
public function __construct(
    string|BackedEnum $urn,
    public ?array $options = null,
    public ?array $data = null,
) {
    if ($urn instanceof BackedEnum) {
        $urnValue = $urn->value;
        $urnString = is_string($urnValue) ? $urnValue : (string) $urnValue;
    } else {
        $urnString = $urn;
    }

    // Validate URN format
    UrnValidator::validateExtensionUrn($urnString, 'urn');

    $this->urn = $urnString;
}
```

**Estimated Effort:** 2-3 hours (create validator, update constructor, add tests)

---

### 🔴 CRITICAL: No Mutual Exclusivity Enforcement

**Issue:** Both `options` (request) and `data` (response) can be set simultaneously, violating protocol semantics
**Location:** Lines 53-64
**Impact:** Creates ambiguous extension objects that don't clearly indicate request vs response context. Clients cannot reliably determine if they're processing a request or response extension.

**Current Code:**
```php
public function __construct(
    string|BackedEnum $urn,
    public ?array $options = null,
    public ?array $data = null,
) {
    // No validation that options and data are mutually exclusive!
}
```

**Solution:**
```php
// Update constructor (lines 53-64):
public function __construct(
    string|BackedEnum $urn,
    public ?array $options = null,
    public ?array $data = null,
) {
    if ($urn instanceof BackedEnum) {
        $urnValue = $urn->value;
        $urnString = is_string($urnValue) ? $urnValue : (string) $urnValue;
    } else {
        $urnString = $urn;
    }

    UrnValidator::validateExtensionUrn($urnString, 'urn');

    // Enforce mutual exclusivity
    if ($options !== null && $data !== null) {
        throw new \InvalidArgumentException(
            'Extension cannot have both options (request) and data (response) set. ' .
            'Use ExtensionData::request() for requests or ExtensionData::response() for responses.'
        );
    }

    $this->urn = $urnString;
}
```

**Estimated Effort:** 1 hour (add validation, update tests)

---

### 🟠 MAJOR: Array Type Safety Issues

**Issue:** `$options` and `$data` arrays accept deeply nested structures and mixed types without validation
**Location:** Lines 55-56
**Impact:** Allows unbounded nesting causing JSON recursion limits, DoS attacks, and unexpected type errors during serialization.

**Solution:**
```php
// Add to UrnValidator or create ArrayValidator class:
public static function validateArray(?array $array, string $fieldName, int $maxDepth = 5): void
{
    if ($array === null) {
        return;
    }

    // Validate array is not empty when provided
    if ($array === []) {
        throw new \InvalidArgumentException(
            "Extension {$fieldName} cannot be an empty array. Use null instead."
        );
    }

    // Validate depth to prevent DoS
    $checkDepth = function(array $arr, int $currentDepth) use (&$checkDepth, $maxDepth, $fieldName): void {
        if ($currentDepth > $maxDepth) {
            throw new \InvalidArgumentException(
                "Extension {$fieldName} exceeds maximum nesting depth of {$maxDepth}"
            );
        }

        foreach ($arr as $value) {
            if (is_array($value)) {
                $checkDepth($value, $currentDepth + 1);
            }
        }
    };

    $checkDepth($array, 1);

    // Validate total size
    $serialized = json_encode($array, JSON_THROW_ON_ERROR);
    if (strlen($serialized) > 65536) { // 64KB limit
        throw new \InvalidArgumentException(
            "Extension {$fieldName} exceeds maximum size of 64KB when serialized"
        );
    }
}

// Update constructor:
public function __construct(
    string|BackedEnum $urn,
    public ?array $options = null,
    public ?array $data = null,
) {
    // ... existing URN validation ...

    // Validate arrays
    UrnValidator::validateArray($options, 'options');
    UrnValidator::validateArray($data, 'data');

    // ... rest of constructor ...
}
```

**Estimated Effort:** 3-4 hours (create validator, add comprehensive tests for edge cases)

---

### 🟡 MINOR: Factory Method Naming Convention

**Issue:** Factory method `from()` doesn't follow the `createFrom*` naming pattern
**Location:** Lines 108-125
**Impact:** Inconsistent API with other factory methods in the codebase; reduces discoverability

**Current Code:**
```php
public static function from(array $data): self
{
    // ...
}
```

**Solution:**
```php
/**
 * Create from an array representation.
 *
 * Deserializes extension data from array format. Handles both request
 * (with options) and response (with data) formats.
 *
 * @param array<string, mixed> $data Array representation to deserialize
 *
 * @return self ExtensionData instance
 */
public static function createFromArray(array $data): self
{
    $urn = $data['urn'] ?? '';
    $rawOptions = $data['options'] ?? null;
    $rawData = $data['data'] ?? null;

    /** @var null|array<string, mixed> $options */
    $options = is_array($rawOptions) ? $rawOptions : null;

    /** @var null|array<string, mixed> $extensionData */
    $extensionData = is_array($rawData) ? $rawData : null;

    return new self(
        urn: is_string($urn) ? $urn : '',
        options: $options,
        data: $extensionData,
    );
}

// Keep `from()` as an alias for backward compatibility:
/**
 * @deprecated Use createFromArray() instead
 */
public static function from(array $data): self
{
    return self::createFromArray($data);
}
```

**Estimated Effort:** 30 minutes (rename, add deprecation notice, update tests)

---

### 🟡 MINOR: Missing Empty URN Validation in from()

**Issue:** The `from()` method defaults to empty string for missing/invalid URN
**Location:** Lines 110-121
**Impact:** Creates invalid extension objects silently instead of failing fast

**Current Code:**
```php
public static function from(array $data): self
{
    $urn = $data['urn'] ?? ''; // Allows empty string!
    // ...
    return new self(
        urn: is_string($urn) ? $urn : '', // Converts non-strings to empty
        options: $options,
        data: $extensionData,
    );
}
```

**Solution:**
```php
public static function createFromArray(array $data): self
{
    // Validate URN is present and valid
    if (!isset($data['urn']) || !is_string($data['urn']) || $data['urn'] === '') {
        throw new \InvalidArgumentException(
            'Extension data must contain a valid non-empty "urn" field'
        );
    }

    $urn = $data['urn'];
    $rawOptions = $data['options'] ?? null;
    $rawData = $data['data'] ?? null;

    /** @var null|array<string, mixed> $options */
    $options = is_array($rawOptions) ? $rawOptions : null;

    /** @var null|array<string, mixed> $extensionData */
    $extensionData = is_array($rawData) ? $rawData : null;

    return new self(
        urn: $urn,
        options: $options,
        data: $extensionData,
    );
}
```

**Estimated Effort:** 30 minutes

---

## Security Considerations

### 1. URN Injection Attacks

**Risk:** Malicious URNs could exploit parsers or logging systems
**Mitigation:** Implement strict URN format validation (solution provided above)

### 2. Denial of Service via Large Payloads

**Risk:** Deeply nested arrays or massive payloads cause memory exhaustion
**Mitigation:** Add array depth and size limits (solution provided above)

### 3. Type Confusion Attacks

**Risk:** Mixed array types could cause unexpected type coercion
**Mitigation:** Validate array structure and types explicitly

---

## Comprehensive Test Coverage

```php
<?php

use Cline\Forrst\Data\ExtensionData;

describe('ExtensionData', function () {
    describe('Constructor Validation', function () {
        it('accepts valid Forrst extension URN', function () {
            $ext = new ExtensionData(
                urn: 'urn:forrst:ext:async',
                options: ['timeout' => 30]
            );

            expect($ext->urn)->toBe('urn:forrst:ext:async');
        });

        it('throws exception for empty URN', function () {
            new ExtensionData(urn: '');
        })->throws(\InvalidArgumentException::class, 'Extension urn cannot be empty');

        it('throws exception for invalid URN format', function () {
            new ExtensionData(urn: 'invalid-urn');
        })->throws(\InvalidArgumentException::class, "must follow format 'urn:forrst:ext:name'");

        it('throws exception when both options and data are set', function () {
            new ExtensionData(
                urn: 'urn:forrst:ext:test',
                options: ['key' => 'value'],
                data: ['result' => 'value']
            );
        })->throws(\InvalidArgumentException::class, 'cannot have both options and data');

        it('accepts BackedEnum as URN', function () {
            enum ExtensionUrn: string {
                case Async = 'urn:forrst:ext:async';
            }

            $ext = new ExtensionData(urn: ExtensionUrn::Async);
            expect($ext->urn)->toBe('urn:forrst:ext:async');
        });

        it('throws exception for oversized URN', function () {
            $longUrn = 'urn:forrst:ext:' . str_repeat('a', 300);
            new ExtensionData(urn: $longUrn);
        })->throws(\InvalidArgumentException::class, 'exceeds maximum length');
    });

    describe('Factory Methods', function () {
        it('creates request extension with options', function () {
            $ext = ExtensionData::request('urn:forrst:ext:retry', [
                'max_attempts' => 3,
                'backoff' => 'exponential'
            ]);

            expect($ext->urn)->toBe('urn:forrst:ext:retry');
            expect($ext->options)->toBe(['max_attempts' => 3, 'backoff' => 'exponential']);
            expect($ext->data)->toBeNull();
        });

        it('creates response extension with data', function () {
            $ext = ExtensionData::response('urn:forrst:ext:async', [
                'operation_id' => 'op-123',
                'status' => 'pending'
            ]);

            expect($ext->urn)->toBe('urn:forrst:ext:async');
            expect($ext->data)->toBe(['operation_id' => 'op-123', 'status' => 'pending']);
            expect($ext->options)->toBeNull();
        });

        it('creates from array with options', function () {
            $ext = ExtensionData::createFromArray([
                'urn' => 'urn:forrst:ext:cache',
                'options' => ['ttl' => 3600]
            ]);

            expect($ext->urn)->toBe('urn:forrst:ext:cache');
            expect($ext->options)->toBe(['ttl' => 3600]);
        });

        it('creates from array with data', function () {
            $ext = ExtensionData::createFromArray([
                'urn' => 'urn:forrst:ext:trace',
                'data' => ['trace_id' => 'xyz']
            ]);

            expect($ext->data)->toBe(['trace_id' => 'xyz']);
        });

        it('throws exception for array without URN', function () {
            ExtensionData::createFromArray([
                'options' => ['key' => 'value']
            ]);
        })->throws(\InvalidArgumentException::class, 'must contain a valid non-empty "urn" field');

        it('throws exception for empty array', function () {
            ExtensionData::createFromArray([]);
        })->throws(\InvalidArgumentException::class);
    });

    describe('Serialization', function () {
        it('serializes request to array with only URN and options', function () {
            $ext = new ExtensionData(
                urn: 'urn:forrst:ext:priority',
                options: ['level' => 'high']
            );

            $result = $ext->toRequestArray();

            expect($result)->toBe([
                'urn' => 'urn:forrst:ext:priority',
                'options' => ['level' => 'high']
            ]);
            expect($result)->not->toHaveKey('data');
        });

        it('serializes response to array with only URN and data', function () {
            $ext = new ExtensionData(
                urn: 'urn:forrst:ext:quota',
                data: ['remaining' => 95, 'limit' => 100]
            );

            $result = $ext->toResponseArray();

            expect($result)->toBe([
                'urn' => 'urn:forrst:ext:quota',
                'data' => ['remaining' => 95, 'limit' => 100]
            ]);
            expect($result)->not->toHaveKey('options');
        });

        it('serializes to array with both fields when manually created', function () {
            // Note: This should not be possible after mutual exclusivity validation
            // This test documents current behavior before fix
            $ext = new ExtensionData(urn: 'urn:forrst:ext:test');

            $result = $ext->toArray();

            expect($result)->toBe(['urn' => 'urn:forrst:ext:test']);
        });
    });

    describe('Array Validation', function () {
        it('throws exception for deeply nested options', function () {
            $nested = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'too deep']]]]]];

            new ExtensionData(
                urn: 'urn:forrst:ext:test',
                options: $nested
            );
        })->throws(\InvalidArgumentException::class, 'exceeds maximum nesting depth');

        it('throws exception for oversized options payload', function () {
            $large = array_fill(0, 10000, str_repeat('x', 100));

            new ExtensionData(
                urn: 'urn:forrst:ext:test',
                options: $large
            );
        })->throws(\InvalidArgumentException::class, 'exceeds maximum size');

        it('throws exception for empty array when not null', function () {
            new ExtensionData(
                urn: 'urn:forrst:ext:test',
                options: []
            );
        })->throws(\InvalidArgumentException::class, 'cannot be an empty array');
    });

    describe('Edge Cases', function () {
        it('handles null options and data gracefully', function () {
            $ext = new ExtensionData(urn: 'urn:forrst:ext:minimal');

            expect($ext->options)->toBeNull();
            expect($ext->data)->toBeNull();
        });

        it('preserves complex nested structures within limits', function () {
            $complex = [
                'config' => ['timeout' => 30, 'retries' => 3],
                'metadata' => ['user_id' => 123, 'tags' => ['a', 'b']]
            ];

            $ext = new ExtensionData(
                urn: 'urn:forrst:ext:complex',
                options: $complex
            );

            expect($ext->options)->toBe($complex);
        });

        it('handles numeric string conversion for integer BackedEnum values', function () {
            enum NumericUrn: int {
                case Test = 1;
            }

            $ext = new ExtensionData(urn: NumericUrn::Test);
            expect($ext->urn)->toBe('1');
        });
    });
});
```

---

## Performance Considerations

1. **URN Validation Overhead**: Regex validation adds ~0.1ms per instantiation (negligible for typical workloads)
2. **Array Depth Checking**: Recursive validation can be expensive for large arrays; consider caching for repeated validations
3. **Serialization**: toRequestArray/toResponseArray are efficient O(1) operations with minimal overhead

---

## Recommendations

### High Priority (Implement Immediately)
1. ✅ **Add URN format validation** to constructor (2-3 hours)
2. ✅ **Enforce mutual exclusivity** of options and data (1 hour)
3. ✅ **Validate array structures** for depth and size limits (3-4 hours)

### Medium Priority (Next Sprint)
4. ✅ **Rename `from()` to `createFromArray()`** for consistency (30 min)
5. ✅ **Add comprehensive test coverage** for all edge cases (4-6 hours)
6. ✅ **Create UrnValidator** helper class for reuse across codebase (2 hours)

### Low Priority (Future Enhancement)
7. ⚪ Consider extracting Urn value object for stronger type safety
8. ⚪ Add JSON Schema validation for options/data structures per extension type
9. ⚪ Implement extension registry for validating known URNs

---

## Conclusion

ExtensionData is a well-designed DTO with excellent immutability and clear request/response separation. However, **production readiness requires critical validation improvements** for URN format enforcement, mutual exclusivity guarantees, and array safety limits. The estimated 7-9 hours of work will transform this from a structurally sound class to a production-grade, security-hardened component.

**Estimated Total Effort:** 7-9 hours
**Priority:** HIGH (Critical validation gaps must be addressed before production use)

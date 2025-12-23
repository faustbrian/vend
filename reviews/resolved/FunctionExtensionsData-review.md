# Code Review: FunctionExtensionsData.php

**File**: `/Users/brian/Developer/cline/forrst/src/Discovery/FunctionExtensionsData.php`
**Type**: Data Transfer Object (DTO)
**Extends**: `Spatie\LaravelData\Data`
**Review Date**: 2025-12-23  
**Reviewer**: Senior Code Review Architect

---

## Executive Summary

FunctionExtensionsData allows individual functions to override server-wide extension settings using allowlist (`supported`) or blocklist (`excluded`) arrays. The class is simple with good documentation but contains a critical architectural flaw: missing validation for the documented mutual exclusivity between `$supported` and `$excluded` fields. Additionally, extension names are not validated against expected URN format. This creates ambiguous configurations that can lead to runtime errors or undefined behavior.

**Overall Assessment**: 🟠 Major Issues  
**SOLID Compliance**: 70%  
**Maintainability Score**: B-  
**Security Risk**: Low  
**Refactoring Need**: Medium

---

## Detailed Analysis

### 1. SOLID Principles Evaluation

#### Single Responsibility Principle (SRP) ✅
**Status**: Compliant  
**Analysis**: Class has one responsibility: declaring per-function extension support configuration.

#### Open/Closed Principle (OCP) ✅
**Status**: Compliant  
**Analysis**: Final class—extension through composition, not inheritance.

#### Liskov Substitution Principle (LSP) ✅
**Status**: Compliant (N/A)  
**Analysis**: No substitution hierarchy.

#### Interface Segregation Principle (ISP) ✅  
**Status**: Compliant  
**Analysis**: Minimal interface with only necessary fields.

#### Dependency Inversion Principle (DIP) ✅
**Status**: Compliant  
**Analysis**: Depends on Spatie\LaravelData\Data abstraction.

---

### 2. Code Quality Issues

#### 🔴 **CRITICAL**: Missing Mutual Exclusivity Validation
**Location**: Lines 45-48 (constructor)  
**Issue**: PHPDoc lines 37, 43 state that `$supported` and `$excluded` are "mutually exclusive," but constructor doesn't enforce this constraint.

**Current Behavior**: Accepts invalid configurations:
```php
// INVALID but accepted!
$ext = new FunctionExtensionsData(
    supported: ['query', 'batch'],
    excluded: ['cache', 'retry'],  // Both specified!
);
```

**Impact**:
- Ambiguous configuration: which takes precedence?
- Runtime errors when processing extensions
- Undefined behavior across different consumers
- Silent data corruption in discovery documents

**Solution**: Add constructor validation:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FunctionExtensionsData extends Data
{
    public function __construct(
        public readonly ?array $supported = null,
        public readonly ?array $excluded = null,
    ) {
        $this->validateMutualExclusivity();
        $this->validateExtensionNames($supported ?? []);
        $this->validateExtensionNames($excluded ?? []);
    }

    /**
     * Ensure supported and excluded are mutually exclusive.
     *
     * @throws InvalidArgumentException
     */
    private function validateMutualExclusivity(): void
    {
        if ($this->supported !== null && $this->excluded !== null) {
            throw new InvalidArgumentException(
                'Cannot specify both "supported" and "excluded"—they are mutually exclusive. '
                .'Use "supported" for allowlist or "excluded" for blocklist, not both.'
            );
        }

        // Validate at least one is specified or both are null (inherit server defaults)
        if ($this->supported === null && $this->excluded === null) {
            // This is valid—inherits server-wide extension settings
            return;
        }

        // Validate arrays are not empty
        if ($this->supported !== null && empty($this->supported)) {
            throw new InvalidArgumentException(
                'Supported extensions array cannot be empty—use null to inherit server defaults'
            );
        }

        if ($this->excluded !== null && empty($this->excluded)) {
            throw new InvalidArgumentException(
                'Excluded extensions array cannot be empty—use null for no exclusions'
            );
        }
    }

    /**
     * Validate extension names follow expected format.
     *
     * @param array<int, string> $extensions
     * @throws InvalidArgumentException
     */
    private function validateExtensionNames(array $extensions): void
    {
        foreach ($extensions as $index => $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException(
                    "Extension name at index {$index} must be a string, got: ".gettype($name)
                );
            }

            // Extension names should follow kebab-case or URN format
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $name) && !str_starts_with($name, 'urn:')) {
                throw new InvalidArgumentException(
                    "Invalid extension name '{$name}' at index {$index}. "
                    ."Must be kebab-case (e.g., 'query', 'atomic-lock') or URN format "
                    ."(e.g., 'urn:forrst:ext:query')"
                );
            }

            // Warn about uppercase (common mistake)
            if ($name !== strtolower($name) && !str_starts_with($name, 'urn:')) {
                trigger_error(
                    "Warning: Extension name '{$name}' contains uppercase characters. "
                    ."Extension names are case-sensitive and typically lowercase.",
                    E_USER_WARNING
                );
            }
        }
    }
}
```

---

#### 🟡 **MINOR**: No Duplicate Detection
**Location**: Lines 46-47  
**Issue**: Arrays can contain duplicate extension names, leading to redundant configuration.

**Solution**: Add duplicate detection:

```php
private function validateNoDuplicates(array $extensions, string $fieldName): void
{
    $unique = array_unique($extensions);
    if (count($unique) !== count($extensions)) {
        $duplicates = array_diff_key($extensions, $unique);
        throw new InvalidArgumentException(
            "Field '{$fieldName}' contains duplicate extension names: "
            .json_encode(array_values($duplicates))
        );
    }
}
```

---

#### 🔵 **SUGGESTION**: Add Named Constructors
**Enhancement**: Add factory methods for common patterns:

```php
/**
 * Create allowlist configuration (only specified extensions supported).
 *
 * @param array<int, string> $extensions Extension names to allow
 */
public static function allow(array $extensions): self
{
    if (empty($extensions)) {
        throw new InvalidArgumentException(
            'Allow list must contain at least one extension'
        );
    }

    return new self(supported: $extensions, excluded: null);
}

/**
 * Create blocklist configuration (all except specified extensions supported).
 *
 * @param array<int, string> $extensions Extension names to block
 */
public static function deny(array $extensions): self
{
    if (empty($extensions)) {
        throw new InvalidArgumentException(
            'Deny list must contain at least one extension'
        );
    }

    return new self(supported: null, excluded: $extensions);
}

/**
 * Inherit all server-wide extension settings (no overrides).
 */
public static function inherit(): self
{
    return new self(supported: null, excluded: null);
}
```

**Usage**:
```php
// Before
$ext = new FunctionExtensionsData(supported: ['query', 'batch'], excluded: null);

// After
$ext = FunctionExtensionsData::allow(['query', 'batch']);
```

---

### 3. Testing Recommendations

```php
<?php

use Cline\Forrst\Discovery\FunctionExtensionsData;

describe('FunctionExtensionsData', function () {
    describe('Happy Path', function () {
        it('creates allowlist configuration', function () {
            $ext = FunctionExtensionsData::allow(['query', 'batch']);

            expect($ext->supported)->toBe(['query', 'batch'])
                ->and($ext->excluded)->toBeNull();
        });

        it('creates blocklist configuration', function () {
            $ext = FunctionExtensionsData::deny(['cache', 'retry']);

            expect($ext->excluded)->toBe(['cache', 'retry'])
                ->and($ext->supported)->toBeNull();
        });

        it('inherits server defaults when both null', function () {
            $ext = FunctionExtensionsData::inherit();

            expect($ext->supported)->toBeNull()
                ->and($ext->excluded)->toBeNull();
        });

        it('accepts URN-format extension names', function () {
            $ext = FunctionExtensionsData::allow([
                'urn:forrst:ext:query',
                'urn:forrst:ext:batch',
            ]);

            expect($ext->supported)->toHaveCount(2);
        });
    });

    describe('Sad Path - Validation Errors', function () {
        it('rejects both supported and excluded', function () {
            expect(fn () => new FunctionExtensionsData(
                supported: ['query'],
                excluded: ['cache'],
            ))->toThrow(InvalidArgumentException::class, 'mutually exclusive');
        });

        it('rejects empty supported array', function () {
            expect(fn () => FunctionExtensionsData::allow([]))
                ->toThrow(InvalidArgumentException::class, 'at least one');
        });

        it('rejects empty excluded array', function () {
            expect(fn () => FunctionExtensionsData::deny([]))
                ->toThrow(InvalidArgumentException::class, 'at least one');
        });

        it('rejects invalid extension name format', function () {
            expect(fn () => FunctionExtensionsData::allow(['Invalid_Name']))
                ->toThrow(InvalidArgumentException::class, 'kebab-case');
        });

        it('rejects non-string extension names', function () {
            expect(fn () => FunctionExtensionsData::allow([123]))
                ->toThrow(InvalidArgumentException::class, 'must be a string');
        });

        it('rejects duplicate extension names', function () {
            expect(fn () => FunctionExtensionsData::allow(['query', 'batch', 'query']))
                ->toThrow(InvalidArgumentException::class, 'duplicate');
        });
    });

    describe('Edge Cases', function () {
        it('warns about uppercase extension names', function () {
            expect(fn () => FunctionExtensionsData::allow(['Query']))
                ->toTrigger(E_USER_WARNING, 'uppercase');
        });

        it('handles single extension', function () {
            $ext = FunctionExtensionsData::allow(['query']);

            expect($ext->supported)->toHaveCount(1);
        });

        it('handles many extensions', function () {
            $many = array_map(fn($i) => "ext-{$i}", range(1, 50));
            $ext = FunctionExtensionsData::allow($many);

            expect($ext->supported)->toHaveCount(50);
        });
    });
});
```

---

## Summary of Recommendations

### Critical (Must Fix Before Production)
1. **Add mutual exclusivity validation** between `supported` and `excluded`
2. **Validate extension name format** (kebab-case or URN)
3. **Add duplicate detection** to prevent redundant entries

### High Priority (Should Fix)
1. **Add named constructors** (::allow(), ::deny(), ::inherit())
2. **Validate arrays are non-empty** when specified
3. **Add comprehensive test suite**

### Medium Priority (Consider)
1. **Warn about uppercase extension names**
2. **Document standard extension names** with examples
3. **Add helper methods** (hasExtension(), isAllowlist(), isBlocklist())

---

## Conclusion

FunctionExtensionsData has a clean design but dangerous lack of validation. The documented mutual exclusivity constraint MUST be enforced at runtime to prevent ambiguous configurations. Adding validation is trivial (20 lines of code) and prevents an entire class of configuration errors.

**Recommended Action**: 🔴 **Block production deployment** until mutual exclusivity validation is added.

**Estimated Effort**: 2-3 hours for validation + comprehensive tests.

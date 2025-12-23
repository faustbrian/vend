# Code Review: FunctionDescriptorData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/FunctionDescriptorData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

FunctionDescriptorData is the most complex DTO in the Discovery module, representing complete function contracts with extensive metadata. While comprehensively documented, the class has critical validation gaps around mutually exclusive fields, missing array type enforcement, and no semantic version validation. This is a cornerstone class requiring production-grade validation.

**Overall Assessment:** 🔴 Critical Issues
**Recommendation:** Implement comprehensive validation before production deployment

---

## SOLID Principles Analysis

All SOLID principles satisfied. Well-designed immutable DTO with clear responsibility.

---

## Code Quality Issues

### 🔴 CRITICAL: No Validation for Required Fields (Lines 101-123)

**Issue:** Required fields `$name`, `$version`, and `$arguments` have no validation for emptiness or format.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/FunctionDescriptorData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $name,
    public readonly string $version,
    public readonly array $arguments,
    // ... other parameters
) {
    // Validate name (URN format)
    if (trim($name) === '') {
        throw new InvalidArgumentException('Function name cannot be empty');
    }

    if (!preg_match('/^urn:[a-z][a-z0-9-]*:forrst:fn:[a-z][a-z0-9:.]*$/i', $name)) {
        throw new InvalidArgumentException(
            "Invalid function URN: '{$name}'. Expected format: 'urn:namespace:forrst:fn:function:name'"
        );
    }

    // Validate semantic version
    $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
        '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)' .
        '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'  .
        '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    if (!preg_match($semverPattern, $version)) {
        throw new InvalidArgumentException(
            "Invalid semantic version: '{$version}'. Must follow semver format (e.g., '1.0.0')"
        );
    }

    // Validate arguments array type
    foreach ($arguments as $index => $argument) {
        if (!$argument instanceof ArgumentData) {
            throw new InvalidArgumentException(
                "Argument at index {$index} must be instanceof ArgumentData"
            );
        }
    }

    // Validate mutually exclusive fields
    $this->validateMutuallyExclusiveFields();
}

/**
 * Validate fields that cannot coexist.
 */
private function validateMutuallyExclusiveFields(): void
{
    // tags, errors, examples, simulations, links arrays must contain proper types
    if ($this->tags !== null) {
        foreach ($this->tags as $tag) {
            if (!is_array($tag) && !$tag instanceof TagData) {
                throw new InvalidArgumentException('Tags must be arrays or TagData instances');
            }
        }
    }

    if ($this->errors !== null) {
        foreach ($this->errors as $error) {
            if (!is_array($error) && !$error instanceof ErrorDefinitionData) {
                throw new InvalidArgumentException('Errors must be arrays or ErrorDefinitionData instances');
            }
        }
    }

    // sideEffects must use standard values
    if ($this->sideEffects !== null) {
        $validEffects = ['create', 'update', 'delete', 'read'];
        foreach ($this->sideEffects as $effect) {
            if (!in_array($effect, $validEffects, true)) {
                throw new InvalidArgumentException(
                    "Invalid side effect: '{$effect}'. Must be one of: " . implode(', ', $validEffects)
                );
            }
        }
    }
}
```

### 🔴 CRITICAL: Mixed Array/Object Types Not Enforced (Lines 108-120)

**Issue:** Arrays like `$tags`, `$errors`, `$links` accept "array<mixed>" instead of strictly typed arrays.

**Impact:** Runtime type errors, difficult debugging, unreliable data structures.

**Solution:** Implemented in validation above.

---

## Test Coverage Recommendations

```php
it('creates valid function descriptor', function () {
    $descriptor = new FunctionDescriptorData(
        name: 'urn:acme:forrst:fn:users:get',
        version: '1.0.0',
        arguments: [
            new ArgumentData(name: 'userId', schema: ['type' => 'integer'], required: true),
        ],
        summary: 'Get user by ID',
        result: new ResultDescriptorData(resource: 'user'),
    );

    expect($descriptor->name)->toContain('urn:');
});

it('rejects invalid URN format', function () {
    expect(fn() => new FunctionDescriptorData(
        name: 'invalid-name',
        version: '1.0.0',
        arguments: [],
    ))->toThrow(InvalidArgumentException::class, 'Invalid function URN');
});

it('rejects invalid semantic version', function () {
    expect(fn() => new FunctionDescriptorData(
        name: 'urn:test:forrst:fn:test',
        version: 'v1',
        arguments: [],
    ))->toThrow(InvalidArgumentException::class, 'Invalid semantic version');
});

it('rejects invalid argument types', function () {
    expect(fn() => new FunctionDescriptorData(
        name: 'urn:test:forrst:fn:test',
        version: '1.0.0',
        arguments: ['not-ArgumentData'],
    ))->toThrow(InvalidArgumentException::class, 'must be instanceof ArgumentData');
});

it('rejects invalid side effects', function () {
    expect(fn() => new FunctionDescriptorData(
        name: 'urn:test:forrst:fn:test',
        version: '1.0.0',
        arguments: [],
        sideEffects: ['invalid-effect'],
    ))->toThrow(InvalidArgumentException::class, 'Invalid side effect');
});
```

---

## Summary

### Critical Issues
1. 🔴 Add URN format validation
2. 🔴 Add semantic version validation
3. 🔴 Enforce array element types
4. 🔴 Validate side effects values

### Estimated Effort: 6-8 hours

This is the most critical class in the module and requires comprehensive validation.

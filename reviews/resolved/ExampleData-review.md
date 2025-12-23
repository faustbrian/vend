# Code Review: ExampleData.php

**File**: `/Users/brian/Developer/cline/forrst/src/Discovery/ExampleData.php`
**Type**: Data Transfer Object (DTO)
**Extends**: `Spatie\LaravelData\Data`
**Review Date**: 2025-12-23
**Reviewer**: Senior Code Review Architect

---

## Executive Summary

ExampleData is a final, immutable DTO designed to support multiple usage patterns: component-level value examples and function-level request/response examples. While the class demonstrates good documentation and readonly immutability, it suffers from significant architectural issues due to poor separation of concerns—attempting to serve two distinct purposes within a single class leads to mutually exclusive field combinations, semantic ambiguity, and complex validation requirements. The class would benefit from splitting into two specialized DTOs (`ValueExampleData` and `FunctionExampleData`) or implementing a discriminated union pattern.

**Overall Assessment**: 🟠 Major Issues  
**SOLID Compliance**: 60%  
**Maintainability Score**: C+  
**Refactoring Recommended**: Yes

---

## Detailed Analysis

### 1. SOLID Principles Evaluation

#### Single Responsibility Principle (SRP) 🔴
**Status**: **VIOLATED**  
**Issue**: The class attempts to serve two fundamentally different purposes:
1. **Value Examples** (`components.examples`) — Simple value with metadata using `$value` or `$externalValue`
2. **Function Examples** — Request/response pairs using `$arguments`, `$result`, and `$error`

**Impact**:
- Developers must remember which fields are valid for which context
- 40% of fields are unused in any given instance (code smell)
- Validation logic must check mutually exclusive field combinations
- Serialization includes irrelevant null fields

**Evidence from Code**:
```php
public function __construct(
    public readonly string $name,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly mixed $value = null,           // Only for value examples
    public readonly ?string $externalValue = null,  // Only for value examples
    public readonly ?array $arguments = null,       // Only for function examples
    public readonly mixed $result = null,           // Only for function examples
    public readonly ?array $error = null,           // Only for function examples
) {}
```

**Solution**: Split into two specialized classes:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Simple value example for components.examples.
 */
final class ValueExampleData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly mixed $value = null,
        public readonly ?string $externalValue = null,
    ) {
        if ($value !== null && $externalValue !== null) {
            throw new \InvalidArgumentException(
                'Cannot specify both value and externalValue—they are mutually exclusive'
            );
        }

        if ($value === null && $externalValue === null) {
            throw new \InvalidArgumentException(
                'Must specify either value or externalValue'
            );
        }
    }
}

/**
 * Function invocation example with request/response pairing.
 */
final class FunctionExampleData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?array $arguments = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {
        if ($result !== null && $error !== null) {
            throw new \InvalidArgumentException(
                'Cannot specify both result and error—use separate examples'
            );
        }
    }
}
```

---

#### Open/Closed Principle (OCP) ⚠️
**Status**: Partial Compliance  
**Issue**: The `final` keyword prevents extension, which is appropriate for DTOs. However, the dual-purpose design makes it difficult to extend behavior for either use case without affecting the other.

---

#### Liskov Substitution Principle (LSP) ✅
**Status**: Compliant  
**Analysis**: As a final DTO, LSP doesn't apply—no substitution hierarchy.

---

#### Interface Segregation Principle (ISP) 🔴
**Status**: **VIOLATED**  
**Issue**: Clients using this class for value examples must depend on function example fields (`$arguments`, `$result`, `$error`) they never use, and vice versa.

**Impact**: Violates ISP's principle that "no client should be forced to depend on methods it does not use."

---

#### Dependency Inversion Principle (DIP) ✅
**Status**: Compliant  
**Analysis**: Depends only on `Spatie\LaravelData\Data` abstraction.

---

### 2. Code Quality Issues

#### 🔴 **CRITICAL**: Missing Mutual Exclusivity Validation
**Location**: Lines 55-64 (constructor)  
**Issue**: The constructor doesn't enforce documented mutual exclusivity constraints:
- `$value` XOR `$externalValue` (lines 42-46 state they're mutually exclusive)
- `$result` XOR `$error` (lines 52-53 state they're mutually exclusive)

**Current Behavior**: Accepts invalid combinations like:
```php
// INVALID but accepted
$example = new ExampleData(
    name: 'Invalid',
    value: 'some value',
    externalValue: 'https://example.com/value.json',  // Both specified!
    result: ['data' => 'result'],
    error: ['code' => 'ERROR'],  // Both result AND error!
);
```

**Solution**: Add validation in constructor:

```php
public function __construct(
    public readonly string $name,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly mixed $value = null,
    public readonly ?string $externalValue = null,
    public readonly ?array $arguments = null,
    public readonly mixed $result = null,
    public readonly ?array $error = null,
) {
    $this->validateMutualExclusivity();
}

private function validateMutualExclusivity(): void
{
    // value and externalValue are mutually exclusive
    if ($this->value !== null && $this->externalValue !== null) {
        throw new \InvalidArgumentException(
            'Cannot specify both "value" and "externalValue"—they are mutually exclusive'
        );
    }

    // result and error are mutually exclusive
    if ($this->result !== null && $this->error !== null) {
        throw new \InvalidArgumentException(
            'Cannot specify both "result" and "error"—use separate examples for success/error cases'
        );
    }

    // Validate usage pattern consistency
    $hasValueFields = $this->value !== null || $this->externalValue !== null;
    $hasFunctionFields = $this->arguments !== null || $this->result !== null || $this->error !== null;

    if ($hasValueFields && $hasFunctionFields) {
        throw new \InvalidArgumentException(
            'Cannot mix value example fields (value/externalValue) with function example fields (arguments/result/error)'
        );
    }
}
```

---

#### 🟠 **MAJOR**: No URL Validation for externalValue
**Location**: Line 60  
**Issue**: The `$externalValue` accepts any string without validating it's a valid URL.

**Impact**:
- Invalid URLs propagate through discovery documents
- Client tools fail when attempting to fetch external examples
- No feedback during development

**Solution**:
```php
private function validateExternalValue(): void
{
    if ($this->externalValue !== null) {
        if (!filter_var($this->externalValue, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                "Invalid URL in externalValue: '{$this->externalValue}'"
            );
        }

        // Ensure HTTPS for security
        if (!str_starts_with($this->externalValue, 'https://')) {
            trigger_error(
                "Warning: externalValue should use HTTPS: '{$this->externalValue}'",
                E_USER_WARNING
            );
        }
    }
}
```

---

#### 🟡 **MINOR**: Mixed Type Allows Unsafe Values
**Location**: Lines 59, 62  
**Issue**: `mixed` type for `$value` and `$result` allows any type including resources, closures, or objects that can't be serialized.

**Impact**:
- Runtime serialization failures
- Difficult to debug issues
- No compile-time type safety

**Recommendation**: Document acceptable types or use union types:
```php
/**
 * @param scalar|array|null $value Embedded literal example value. Must be JSON-serializable.
 *                                  Supported types: string, int, float, bool, array, null.
 *                                  Resources and objects are not supported.
 */
public function __construct(
    public readonly string $name,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly string|int|float|bool|array|null $value = null,
    // ...
```

---

### 3. Architecture Recommendations

#### Preferred Solution: Discriminated Union Pattern

If keeping a single class, use an enum discriminator:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

enum ExampleType: string
{
    case Value = 'value';
    case Function = 'function';
}

final class ExampleData extends Data
{
    private function __construct(
        public readonly string $name,
        public readonly ExampleType $type,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly mixed $value = null,
        public readonly ?string $externalValue = null,
        public readonly ?array $arguments = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {}

    public static function value(
        string $name,
        string|int|float|bool|array $value = null,
        ?string $externalValue = null,
        ?string $summary = null,
        ?string $description = null,
    ): self {
        if ($value !== null && $externalValue !== null) {
            throw new \InvalidArgumentException('Cannot specify both value and externalValue');
        }

        return new self(
            name: $name,
            type: ExampleType::Value,
            summary: $summary,
            description: $description,
            value: $value,
            externalValue: $externalValue,
            arguments: null,
            result: null,
            error: null,
        );
    }

    public static function functionSuccess(
        string $name,
        array $arguments,
        mixed $result,
        ?string $summary = null,
        ?string $description = null,
    ): self {
        return new self(
            name: $name,
            type: ExampleType::Function,
            summary: $summary,
            description: $description,
            value: null,
            externalValue: null,
            arguments: $arguments,
            result: $result,
            error: null,
        );
    }

    public static function functionError(
        string $name,
        array $arguments,
        array $error,
        ?string $summary = null,
        ?string $description = null,
    ): self {
        return new self(
            name: $name,
            type: ExampleType::Function,
            summary: $summary,
            description: $description,
            value: null,
            externalValue: null,
            arguments: $arguments,
            result: null,
            error: $error,
        );
    }

    public function isValueExample(): bool
    {
        return $this->type === ExampleType::Value;
    }

    public function isFunctionExample(): bool
    {
        return $this->type === ExampleType::Function;
    }
}
```

**Usage**:
```php
// Value example
$valueEx = ExampleData::value(
    name: 'PublishedEvent',
    value: ['status' => 'published', 'timestamp' => 1234567890],
    summary: 'A typical published event',
);

// Function success example
$successEx = ExampleData::functionSuccess(
    name: 'GetEventSuccess',
    arguments: ['eventId' => '123'],
    result: ['event' => ['id' => '123', 'name' => 'Test']],
    summary: 'Successfully retrieving an event',
);

// Function error example
$errorEx = ExampleData::functionError(
    name: 'GetEventNotFound',
    arguments: ['eventId' => '999'],
    error: ['code' => 'NOT_FOUND', 'message' => 'Event not found'],
    summary: 'Event not found error',
);
```

---

### 4. Testing Recommendations

```php
<?php

use Cline\Forrst\Discovery\ExampleData;
use Cline\Forrst\Discovery\ExampleType;

describe('ExampleData', function () {
    describe('Value Examples', function () {
        it('creates value example with embedded value', function () {
            $example = ExampleData::value(
                name: 'SimpleValue',
                value: ['key' => 'value'],
                summary: 'A simple value example',
            );

            expect($example->type)->toBe(ExampleType::Value)
                ->and($example->value)->toBe(['key' => 'value'])
                ->and($example->externalValue)->toBeNull()
                ->and($example->arguments)->toBeNull();
        });

        it('creates value example with external value', function () {
            $example = ExampleData::value(
                name: 'ExternalValue',
                externalValue: 'https://example.com/value.json',
            );

            expect($example->externalValue)->toBe('https://example.com/value.json')
                ->and($example->value)->toBeNull();
        });

        it('rejects both value and externalValue', function () {
            expect(fn () => ExampleData::value(
                name: 'Invalid',
                value: 'data',
                externalValue: 'https://example.com/data.json',
            ))->toThrow(InvalidArgumentException::class, 'mutually exclusive');
        });

        it('rejects invalid URL in externalValue', function () {
            expect(fn () => ExampleData::value(
                name: 'InvalidURL',
                externalValue: 'not-a-url',
            ))->toThrow(InvalidArgumentException::class, 'Invalid URL');
        });
    });

    describe('Function Examples', function () {
        it('creates function success example', function () {
            $example = ExampleData::functionSuccess(
                name: 'GetUser',
                arguments: ['userId' => 123],
                result: ['user' => ['id' => 123, 'name' => 'John']],
            );

            expect($example->type)->toBe(ExampleType::Function)
                ->and($example->arguments)->toBe(['userId' => 123])
                ->and($example->result)->not->toBeNull()
                ->and($example->error)->toBeNull();
        });

        it('creates function error example', function () {
            $example = ExampleData::functionError(
                name: 'UserNotFound',
                arguments: ['userId' => 999],
                error: ['code' => 'NOT_FOUND', 'message' => 'User not found'],
            );

            expect($example->error)->not->toBeNull()
                ->and($example->result)->toBeNull();
        });

        it('rejects both result and error', function () {
            expect(fn () => new ExampleData(
                name: 'Invalid',
                result: ['data'],
                error: ['error'],
            ))->toThrow(InvalidArgumentException::class, 'both result and error');
        });
    });

    describe('Type Checking', function () {
        it('identifies value examples correctly', function () {
            $example = ExampleData::value('Test', value: 'data');

            expect($example->isValueExample())->toBeTrue()
                ->and($example->isFunctionExample())->toBeFalse();
        });

        it('identifies function examples correctly', function () {
            $example = ExampleData::functionSuccess('Test', [], 'result');

            expect($example->isFunctionExample())->toBeTrue()
                ->and($example->isValueExample())->toBeFalse();
        });
    });
});
```

---

## Summary of Recommendations

### Critical (Must Fix)
1. **Add mutual exclusivity validation** for `value`/`externalValue` and `result`/`error`
2. **Validate externalValue is a valid URL** (HTTPS preferred)
3. **Consider architectural refactor** to split into specialized classes or use discriminated union

### High Priority (Should Fix)
1. **Add usage pattern validation** to prevent mixing value fields with function fields
2. **Restrict mixed types** to JSON-serializable types (scalar|array|null)
3. **Add comprehensive test coverage** for all edge cases and invalid combinations

### Medium Priority (Consider)
1. **Implement named constructors** (::value(), ::functionSuccess(), ::functionError())
2. **Add type discriminator** field to enable runtime type checking
3. **Document architectural decision** in ADR if keeping dual-purpose design

---

## Conclusion

ExampleData suffers from a fundamental SRP violation by attempting to serve two distinct purposes. This creates complexity, reduces type safety, and increases the likelihood of developer errors. The class urgently needs either architectural refactoring (split into two classes) or enhanced validation with named constructors to guide correct usage.

**Recommended Action**: Refactor using discriminated union pattern with named constructors as an intermediate step. Consider full class split in next major version.

**Estimated Effort**: 6-8 hours for refactor + comprehensive tests.

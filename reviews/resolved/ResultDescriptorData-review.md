# Code Review: ResultDescriptorData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/ResultDescriptorData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

ResultDescriptorData defines RPC method return types with support for both resource objects and JSON Schema. Well-designed but lacks critical validation for mutually exclusive fields and schema structure.

**Overall Assessment:** 🔴 Critical Issues
**Recommendation:** Enforce mutually exclusive field validation immediately

---

## Code Quality Issues

### 🔴 CRITICAL: No Validation for Mutually Exclusive Fields (Lines 44-49)

**Issue:** Both `$resource` and `$schema` can be set simultaneously despite documentation stating one must be null.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/ResultDescriptorData.php

use InvalidArgumentException;

public function __construct(
    public readonly ?string $resource = null,
    public readonly ?array $schema = null,
    public readonly bool $collection = false,
    public readonly ?string $description = null,
) {
    // Validate mutually exclusive fields
    if ($this->resource !== null && $this->schema !== null) {
        throw new InvalidArgumentException(
            'Cannot specify both "resource" and "schema". Use resource for resource objects ' .
            'or schema for custom return types, but not both.'
        );
    }

    // At least one must be specified
    if ($this->resource === null && $this->schema === null) {
        throw new InvalidArgumentException(
            'Must specify either "resource" or "schema" to define return type'
        );
    }

    // Validate resource name format if provided
    if ($this->resource !== null) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $this->resource)) {
            throw new InvalidArgumentException(
                "Invalid resource name: '{$this->resource}'. Must be snake_case lowercase (e.g., 'user', 'order_item')"
            );
        }
    }

    // Validate JSON Schema structure if provided
    if ($this->schema !== null) {
        $this->validateJsonSchema($this->schema);
    }
}

/**
 * Validate JSON Schema structure.
 *
 * @param array<string, mixed> $schema
 * @throws InvalidArgumentException
 */
private function validateJsonSchema(array $schema): void
{
    if (!isset($schema['type']) && !isset($schema['$ref'])) {
        throw new InvalidArgumentException(
            'JSON Schema must include "type" or "$ref" property'
        );
    }

    if (isset($schema['type'])) {
        $validTypes = ['null', 'boolean', 'object', 'array', 'number', 'string', 'integer'];
        if (!in_array($schema['type'], $validTypes, true)) {
            throw new InvalidArgumentException(
                "Invalid JSON Schema type: '{$schema['type']}'"
            );
        }
    }
}
```

---

## Test Coverage

```php
it('creates resource result', function () {
    $result = new ResultDescriptorData(
        resource: 'user',
        collection: false,
        description: 'Returns a single user object',
    );

    expect($result->resource)->toBe('user')
        ->and($result->schema)->toBeNull();
});

it('creates schema result', function () {
    $result = new ResultDescriptorData(
        schema: ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
        description: 'Custom response structure',
    );

    expect($result->schema)->toHaveKey('type')
        ->and($result->resource)->toBeNull();
});

it('rejects both resource and schema', function () {
    expect(fn() => new ResultDescriptorData(
        resource: 'user',
        schema: ['type' => 'object'],
    ))->toThrow(InvalidArgumentException::class, 'Cannot specify both');
});

it('rejects neither resource nor schema', function () {
    expect(fn() => new ResultDescriptorData())->toThrow(InvalidArgumentException::class, 'Must specify either');
});

it('rejects invalid resource name format', function () {
    expect(fn() => new ResultDescriptorData(
        resource: 'InvalidResourceName',
    ))->toThrow(InvalidArgumentException::class, 'snake_case');
});

it('rejects invalid JSON Schema', function () {
    expect(fn() => new ResultDescriptorData(
        schema: ['invalid' => 'schema'],
    ))->toThrow(InvalidArgumentException::class, 'must include "type"');
});
```

---

## Summary

### Critical Issues
1. 🔴 Enforce mutually exclusive resource/schema validation
2. 🔴 Require at least one field (resource or schema)
3. 🔴 Validate JSON Schema structure

### Major Issues
4. 🟠 Validate resource name format

### Estimated Effort: 3-4 hours

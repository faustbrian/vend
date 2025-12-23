# Code Review: Async Descriptors (Operation Cancel/List/Status)

## Files Reviewed
1. `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Descriptors/OperationCancelDescriptor.php`
2. `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Descriptors/OperationListDescriptor.php`
3. `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Descriptors/OperationStatusDescriptor.php`

**Purpose:** These descriptor classes define discovery metadata for async operation management functions (cancel, list, status) in the Forrst protocol.

---

## Executive Summary

The three descriptor classes follow a consistent pattern: each implements `DescriptorInterface` and provides a static `create()` method that returns a fluent `FunctionDescriptor` builder with argument schemas, result schemas, and error definitions. The code is clean and well-structured, but there are several areas where consistency, validation, and documentation could be improved.

**Severity Breakdown (Combined):**
- Critical: 0 issues
- Major: 2 issues
- Minor: 4 issues
- Suggestions: 2 improvements

---

## SOLID Principles Analysis

### Single Responsibility Principle (SRP): EXCELLENT
Each descriptor has one responsibility: defining the schema and metadata for its corresponding function. No business logic or data manipulation.

### Open/Closed Principle (OCP): PASS
The classes are `final`, indicating they're not intended for extension. The `FunctionDescriptor` builder pattern allows extensibility without modifying these classes.

### Liskov Substitution Principle (LSP): PASS
All three classes implement `DescriptorInterface` and can be substituted interchangeably in descriptor contexts.

### Interface Segregation Principle (ISP): PASS
Implements only `DescriptorInterface`, which appears to have a single method requirement.

### Dependency Inversion Principle (DIP): PASS
No dependencies on concrete implementations.

---

## Major Issues

### 🟠 Major Issue #1: Inconsistent Schema Validation (All Files)

**Location:** Result schema definitions
**Impact:** The result schemas don't validate all properties consistently. Some use `const` for fixed values, others don't. Required fields aren't consistently enforced.

**Problem in OperationCancelDescriptor (lines 38-59):**
```php
->result(
    schema: [
        'type' => 'object',
        'properties' => [
            'operation_id' => [
                'type' => 'string',
                'description' => 'Operation ID',
            ],
            'status' => [
                'type' => 'string',
                'const' => 'cancelled',  // Good: uses const
                'description' => 'New status',
            ],
            'cancelled_at' => [
                'type' => 'string',
                'format' => 'date-time',
                'description' => 'Cancellation timestamp',
            ],
        ],
        'required' => ['operation_id', 'status', 'cancelled_at'],
    ],
    description: 'Operation cancellation response',
)
```

**Problem in OperationListDescriptor (lines 62-89):**
```php
->result(
    schema: [
        'type' => 'object',
        'properties' => [
            'operations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'function' => ['type' => 'string'],
                        'version' => ['type' => 'string'],
                        'status' => ['type' => 'string'],  // Should specify enum
                        'progress' => ['type' => 'number'],  // No bounds validation
                        'started_at' => ['type' => 'string', 'format' => 'date-time'],
                    ],
                    // Missing: 'required' field for item properties
                ],
                'description' => 'List of operations',
            ],
            'next_cursor' => [
                'type' => 'string',
                'description' => 'Pagination cursor for next page',
            ],
        ],
        'required' => ['operations'],  // next_cursor should be optional
    ],
    description: 'Operation list response',
)
```

**Solution:**
Create comprehensive, consistent schemas with proper validation:

```php
// OperationListDescriptor.php
->result(
    schema: [
        'type' => 'object',
        'properties' => [
            'operations' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'pattern' => '^op_[a-f0-9]{24}$',  // Validate operation ID format
                            'description' => 'Unique operation identifier',
                        ],
                        'function' => [
                            'type' => 'string',
                            'description' => 'Function URN that was called',
                        ],
                        'version' => [
                            'type' => 'string',
                            'pattern' => '^\d+$',  // Validate version format
                            'description' => 'Function version',
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['pending', 'processing', 'completed', 'failed', 'cancelled'],
                            'description' => 'Current operation status',
                        ],
                        'progress' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                            'description' => 'Progress percentage (0-1)',
                        ],
                        'started_at' => [
                            'type' => ['string', 'null'],  // Allow null
                            'format' => 'date-time',
                            'description' => 'When operation started processing',
                        ],
                    ],
                    'required' => ['id', 'function', 'version', 'status'],  // Add required fields
                ],
                'description' => 'List of operations matching filter criteria',
            ],
            'next_cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for fetching next page',
            ],
        ],
        'required' => ['operations'],
        'additionalProperties' => false,  // Prevent extra properties
    ],
    description: 'Paginated operation list response',
)
```

**Why This Matters:**
- Clients generate code from these schemas
- Missing validation allows malformed data
- Inconsistent schemas confuse API consumers
- Type generators (TypeScript, Go) rely on accurate schemas

---

### 🟠 Major Issue #2: Missing Argument Validation (OperationListDescriptor)

**Location:** OperationListDescriptor, lines 30-61
**Impact:** The `limit` argument allows values outside the documented range due to improper schema definition.

**Problem Code:**
```php
->argument(
    name: 'limit',
    schema: [
        'type' => 'integer',
        'default' => 50,
        'minimum' => 1,
        'maximum' => 100,
    ],
    required: false,
    description: 'Max results (default 50)',
)
```

**Issue:**
While the schema defines min/max, there's no guarantee the implementation enforces these. Additionally, the schema doesn't specify the behavior when limit is omitted.

**Solution:**
```php
->argument(
    name: 'limit',
    schema: [
        'type' => 'integer',
        'default' => 50,
        'minimum' => 1,
        'maximum' => 100,
        'description' => 'Maximum number of results per page. Defaults to 50 if not specified.',
    ],
    required: false,
    description: 'Maximum results per page (1-100, default 50)',
)
->argument(
    name: 'cursor',
    schema: [
        'type' => 'string',
        'pattern' => '^[a-zA-Z0-9+/=_-]+$',  // Base64-like cursor validation
        'description' => 'Opaque pagination cursor from previous response',
    ],
    required: false,
    description: 'Pagination cursor for retrieving next page',
)
```

Also add implementation validation:

```php
// In OperationListFunction.php
public function __invoke(): array
{
    $limit = $this->requestObject->getArgument('limit', 50);

    // Enforce bounds even if schema validation is bypassed
    if ($limit < 1 || $limit > 100) {
        throw new \InvalidArgumentException(sprintf(
            'Limit must be between 1 and 100, got %d',
            $limit
        ));
    }

    // ... rest of implementation
}
```

---

## Minor Issues

### 🟡 Minor Issue #1: Missing Pattern Validation for operation_id

**Location:** All three descriptors
**Impact:** Operation IDs should follow a specific format (`op_[hex]`) but no pattern validation enforces this.

**Solution:**
```php
// In all descriptors
->argument(
    name: 'operation_id',
    schema: [
        'type' => 'string',
        'pattern' => '^op_[a-f0-9]{24}$',  // Matches bin2hex(random_bytes(12))
        'minLength' => 27,
        'maxLength' => 27,
        'description' => 'Operation identifier (format: op_ followed by 24 hex characters)',
    ],
    required: true,
    description: 'Unique operation identifier',
)
```

---

### 🟡 Minor Issue #2: Incomplete Documentation (OperationStatusDescriptor)

**Location:** OperationStatusDescriptor, lines 62-65
**Impact:** The result schema includes a generic `result` field with no type information.

**Problem Code:**
```php
'result' => [
    'description' => 'Operation result (when completed)',
],
```

**Solution:**
```php
'result' => [
    'description' => 'Function execution result (when status is completed). Type varies by function.',
    'nullable' => true,
],
'errors' => [
    'type' => 'array',
    'items' => [
        'type' => 'object',
        'properties' => [
            'code' => ['type' => 'string'],
            'message' => ['type' => 'string'],
            'details' => ['type' => 'object'],
        ],
        'required' => ['code', 'message'],
    ],
    'description' => 'Error details (when status is failed)',
],
```

---

### 🟡 Minor Issue #3: Missing Examples (All Descriptors)

**Location:** All descriptors
**Impact:** No schema examples provided for developers to understand expected data format.

**Solution:**
```php
// Add to FunctionDescriptor builder
->example(
    name: 'Cancel pending operation',
    request: [
        'operation_id' => 'op_a1b2c3d4e5f6g7h8i9j0k1l2',
    ],
    response: [
        'operation_id' => 'op_a1b2c3d4e5f6g7h8i9j0k1l2',
        'status' => 'cancelled',
        'cancelled_at' => '2025-01-15T10:30:00Z',
    ],
)
```

---

### 🟡 Minor Issue #4: Inconsistent Error Documentation

**Location:** OperationCancelDescriptor vs others
**Impact:** OperationCancelDescriptor defines two error cases, but OperationStatusDescriptor only defines one. OperationListDescriptor defines none.

**OperationCancelDescriptor (lines 60-69):**
```php
->error(
    code: ErrorCode::AsyncOperationNotFound,
    message: 'Operation not found',
    description: 'The specified operation ID does not exist',
)
->error(
    code: ErrorCode::AsyncCannotCancel,
    message: 'Operation cannot be cancelled',
    description: 'The operation has already completed or cannot be cancelled',
)
```

**OperationListDescriptor:**
```php
// NO ERROR DEFINITIONS
```

**Solution - OperationListDescriptor should document possible errors:**
```php
->error(
    code: ErrorCode::InvalidArgument,
    message: 'Invalid filter parameters',
    description: 'One or more filter parameters are invalid',
)
->error(
    code: ErrorCode::InvalidCursor,
    message: 'Invalid pagination cursor',
    description: 'The provided cursor is malformed or expired',
)
```

---

## Suggestions

### 🔵 Suggestion #1: Add Versioning to Descriptors

**Benefit:** As the API evolves, descriptors should track their version to support backward compatibility.

**Implementation:**
```php
final class OperationCancelDescriptor implements DescriptorInterface
{
    private const string DESCRIPTOR_VERSION = '1.0.0';

    public static function create(): FunctionDescriptor
    {
        return FunctionDescriptor::make()
            ->urn(FunctionUrn::OperationCancel)
            ->version(self::DESCRIPTOR_VERSION)
            ->summary('Cancel a pending async operation')
            // ... rest of definition
    }
}
```

---

### 🔵 Suggestion #2: Extract Common Schemas

**Benefit:** Reduce duplication by extracting common schema definitions (operation ID, pagination, timestamps).

**Implementation:**
```php
// Create shared schema definitions
// File: src/Extensions/Async/Schemas/CommonSchemas.php
<?php
namespace Cline\Forrst\Extensions\Async\Schemas;

final class CommonSchemas
{
    public static function operationIdArgument(): array
    {
        return [
            'name' => 'operation_id',
            'schema' => [
                'type' => 'string',
                'pattern' => '^op_[a-f0-9]{24}$',
                'description' => 'Unique operation identifier',
            ],
            'required' => true,
            'description' => 'Operation ID to query',
        ];
    }

    public static function operationStatusEnum(): array
    {
        return [
            'type' => 'string',
            'enum' => ['pending', 'processing', 'completed', 'failed', 'cancelled'],
        ];
    }

    public static function paginationCursor(): array
    {
        return [
            'name' => 'cursor',
            'schema' => [
                'type' => 'string',
                'description' => 'Pagination cursor',
            ],
            'required' => false,
            'description' => 'Cursor for next page',
        ];
    }
}

// Usage in descriptors
public static function create(): FunctionDescriptor
{
    $opIdArg = CommonSchemas::operationIdArgument();

    return FunctionDescriptor::make()
        ->urn(FunctionUrn::OperationCancel)
        ->summary('Cancel a pending async operation')
        ->argument(
            name: $opIdArg['name'],
            schema: $opIdArg['schema'],
            required: $opIdArg['required'],
            description: $opIdArg['description'],
        )
        // ...
}
```

---

## Security Analysis

**No direct security vulnerabilities** in descriptor files (they're metadata only), but:

1. **Input Validation:** Missing pattern validation for operation IDs could allow injection attacks if IDs aren't validated elsewhere
2. **DoS via Pagination:** No maximum page size enforcement could allow DoS through massive result sets

---

## Performance Considerations

**No performance concerns** - these are static schema definitions with minimal runtime overhead.

---

## Testing Recommendations

```php
<?php

use PHPUnit\Framework\TestCase;

final class AsyncDescriptorsTest extends TestCase
{
    public function test_operation_cancel_descriptor_structure(): void
    {
        $descriptor = OperationCancelDescriptor::create();

        $this->assertInstanceOf(FunctionDescriptor::class, $descriptor);
        $this->assertEquals('forrst.operation.cancel', $descriptor->getUrn());

        // Validate schema completeness
        $arguments = $descriptor->getArguments();
        $this->assertArrayHasKey('operation_id', $arguments);
        $this->assertTrue($arguments['operation_id']['required']);

        // Validate error definitions
        $errors = $descriptor->getErrors();
        $this->assertCount(2, $errors);
    }

    public function test_all_descriptors_use_consistent_operation_id_schema(): void
    {
        $descriptors = [
            OperationCancelDescriptor::create(),
            OperationStatusDescriptor::create(),
        ];

        $operationIdSchemas = array_map(
            fn($d) => $d->getArguments()['operation_id']['schema'] ?? null,
            $descriptors
        );

        // All should have identical operation_id schema
        $this->assertCount(1, array_unique($operationIdSchemas, SORT_REGULAR));
    }

    public function test_operation_list_pagination_schema(): void
    {
        $descriptor = OperationListDescriptor::create();
        $arguments = $descriptor->getArguments();

        $this->assertArrayHasKey('limit', $arguments);
        $this->assertEquals(50, $arguments['limit']['schema']['default']);
        $this->assertEquals(100, $arguments['limit']['schema']['maximum']);
    }
}
```

---

## Conclusion

The async descriptor files are well-structured and follow good patterns, but need improvements in:

1. **Schema consistency** - Standardize validation across all descriptors
2. **Argument validation** - Add pattern matching and bounds checking
3. **Error documentation** - Document all possible error conditions
4. **DRY principle** - Extract common schemas to reduce duplication

These are metadata files, so the risk of runtime issues is low, but improving schema quality directly benefits API consumers who generate client code from these definitions.

**Overall Grade: B** (Good structure, needs consistency improvements)

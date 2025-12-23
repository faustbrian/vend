# Code Review: ForbiddenException.php

**File**: `/Users/brian/Developer/cline/forrst/src/Exceptions/ForbiddenException.php`
**Type**: Concrete Exception Class (HTTP Error)
**Lines of Code**: 74
**Complexity**: Low
**Parent**: `AbstractRequestException`

---

## Executive Summary

`ForbiddenException` represents HTTP 403 Forbidden errors in the Forrst protocol, handling authorization failures where authenticated users lack necessary permissions. The implementation includes an `#[Override]` attribute on `getStatusCode()` and uses a unique structured error format with status/title/detail fields. However, there are architectural concerns about the details array structure and a PHPStan suppression that warrants investigation.

**Overall Assessment**: 🟡 **GOOD** - Functional but has architectural questions requiring clarification.

---

## Architectural Analysis

### Design Pattern Implementation

**Pattern**: Factory Method with Structured Error Details

```php
public static function create(?string $detail = null): self
{
    // @phpstan-ignore-next-line argument.type
    return self::new(ErrorCode::Forbidden, 'Forbidden', details: [
        [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => $detail ?? 'You are not authorized to perform this action.',
        ],
    ]);
}
```

🔴 **CRITICAL OBSERVATION**: Details Structure

The details parameter contains a **nested array** with a single element:
```php
details: [
    [                    // ← Outer array
        'status' => ..., // ← Inner associative array
        'title' => ...,
        'detail' => ...,
    ],
]
```

**Questions**:
1. Why is details an array of arrays vs. a single associative array?
2. Is this following a JSON:API spec or similar standard?
3. Can there be multiple error objects in the array?

**Typical Pattern Elsewhere**:
```php
// Most other exceptions:
details: [
    'key' => 'value',
    'another_key' => 'another_value',
]

// This exception:
details: [
    [
        'status' => '403',
        'title' => 'Forbidden',
        'detail' => 'Message',
    ],
]
```

**JSON Output Comparison**:
```json
// Typical exception:
{
  "code": "FORBIDDEN",
  "message": "Forbidden",
  "details": {
    "key": "value"
  }
}

// This exception:
{
  "code": "FORBIDDEN",
  "message": "Forbidden",
  "details": [
    {
      "status": "403",
      "title": "Forbidden",
      "detail": "You are not authorized..."
    }
  ]
}
```

✅ **IF** this follows JSON:API specification (where errors is an array of error objects), this is correct.
⚠️ **IF** this is inconsistent with other exceptions, it creates confusion.

### PHPStan Suppression Analysis

**Line 53**:
```php
// @phpstan-ignore-next-line argument.type
return self::new(ErrorCode::Forbidden, 'Forbidden', details: [
    [...]
]);
```

🟡 **INVESTIGATION NEEDED**: Why is argument.type suppressed?

**Possible Reasons**:
1. **Type Mismatch**: `self::new()` expects `?array<string, mixed>` but receives `array<int, array<string, string>>`
2. **PHPDoc Issue**: Parent method signature doesn't match implementation expectations

**Check Parent Method Signature**:
```bash
# Run to verify:
rg -A 10 "protected static function new" /Users/brian/Developer/cline/forrst/src/Exceptions/AbstractRequestException.php
```

Expected signature from AbstractRequestException review:
```php
protected static function new(
    ErrorCode $code,
    string $message,
    ?SourceData $source = null,
    ?array $details = null,  // ← Should be array<string, mixed>
): static
```

**Issue**: The nested array `[[...]]` is technically `array<int, array<string, string>>`, not `array<string, mixed>`.

**Resolution Options**:

**Option 1**: If JSON:API compliance is intentional, document it:
```php
/**
 * Create a new forbidden exception instance.
 *
 * NOTE: Error details follow JSON:API specification format with an array of
 * error objects. This differs from other Forrst exceptions to maintain
 * JSON:API compatibility for HTTP status exceptions.
 *
 * @see https://jsonapi.org/format/#errors
 */
public static function create(?string $detail = null): self
{
    // PHPStan doesn't recognize JSON:API error array format
    // Details is array<int, array<string, string>> not array<string, mixed>
    // @phpstan-ignore-next-line argument.type
    return self::new(ErrorCode::Forbidden, 'Forbidden', details: [
        [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => $detail ?? 'You are not authorized to perform this action.',
        ],
    ]);
}
```

**Option 2**: If consistency is preferred, flatten the structure:
```php
public static function create(?string $detail = null): self
{
    return self::new(
        ErrorCode::Forbidden,
        'Forbidden',
        details: [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => $detail ?? 'You are not authorized to perform this action.',
        ],
    );
}
```

### Override Attribute

```php
#[Override()]
public function getStatusCode(): int
{
    return 403;
}
```

✅ **EXCELLENT**: PHP 8.3+ `#[Override]` attribute ensures the method actually overrides a parent method, preventing typos and refactoring errors.

**Observation**: Empty parentheses are unnecessary:
```php
// Current:
#[Override()]

// Equivalent:
#[Override]
```

Both are valid, but `#[Override]` without parentheses is more concise.

---

## Code Quality Evaluation

### Readability: ⭐⭐⭐⭐ (4/5)

**Good Documentation**:
```php
/**
 * Exception for forbidden access errors (HTTP 403).
 *
 * Part of the Forrst protocol HTTP error exceptions. Represents authorization failures
 * where the client is authenticated but lacks permission to access the requested
 * resource or perform the requested action...
 */
```

✅ **CLEAR**: Explains the distinction between 403 (authenticated but unauthorized) vs. 401 (unauthenticated).

**-1 Point**: The nested array structure and PHPStan suppression lack explanation.

### Type Safety: ⭐⭐⭐ (3/5)

```php
<?php declare(strict_types=1);
```

- Strict types enabled ✅
- Return types declared ✅
- Parameter types declared ✅
- PHPStan suppression suggests type issue ⚠️

**Concern**: Suppressing type checks without explanation reduces confidence in type safety.

### Naming Conventions: ⭐⭐⭐⭐⭐ (5/5)

- Class name: Clear HTTP error mapping ✅
- Method names: Standard (`create`, `getStatusCode`) ✅
- Parameter names: Descriptive (`$detail`) ✅

---

## Security Audit

### 🟢 Secure Default Message

```php
$detail ?? 'You are not authorized to perform this action.'
```

✅ **SECURE**: Generic default message doesn't leak authorization logic details.

### 🟡 Custom Detail Message Exposure

**Potential Risk**:
```php
public static function create(?string $detail = null): self
```

Developers might pass detailed authorization failure reasons:
```php
// ⚠️ Too detailed:
throw ForbiddenException::create('User lacks admin role and owner_id=123 check failed');

// ✅ Better:
throw ForbiddenException::create('Insufficient permissions to delete this resource');
```

**Recommendation**: Add security guidelines to DocBlock:

```php
/**
 * Create a new forbidden exception instance.
 *
 * Factory method that constructs a forbidden exception with a standardized error
 * structure containing status code, title, and detail message. Used when a user
 * is authenticated but lacks the necessary permissions for the requested operation.
 *
 * SECURITY: The detail message is returned to clients. Do not include sensitive
 * information such as:
 * - Internal role/permission names
 * - Database IDs or internal identifiers
 * - Authorization logic implementation details
 * - User data from authorization checks
 *
 * @param  null|string $detail Optional detailed error message explaining why access
 *                             was denied. Defaults to a generic authorization failure
 *                             message if not provided. Keep messages user-friendly
 *                             and avoid exposing internal authorization mechanisms.
 */
```

### 🟢 Status Code Hardcoded

```php
'status' => '403',
```

✅ **GOOD**: Status is a string literal, matching getStatusCode() return value.

**Observation**: Why is status a string `'403'` instead of integer `403`?

Likely for JSON:API compliance where status is defined as a string:
```json
{
  "status": "403"  // String in JSON:API spec
}
```

---

## Performance Analysis

### 🟢 Optimal Factory

- Time Complexity: O(1)
- Space Complexity: O(1)
- No heavy operations

✅ **NO CONCERNS**

---

## Maintainability Assessment

### 🟡 Inconsistent Error Structure

If this exception's nested array structure is unique in the codebase, it creates maintainability issues:
1. Clients must handle two different error formats
2. Middleware/handlers need special cases
3. Documentation complexity increases

**Verification Needed**:
```bash
# Check if other HTTP exceptions use the same pattern:
rg -A 5 "details: \[" /Users/brian/Developer/cline/forrst/src/Exceptions/*.php
```

### 🟢 Single Responsibility

The exception has one clear purpose: represent HTTP 403 errors.

✅ **EXCELLENT**

### 🟢 Final Class

```php
final class ForbiddenException extends AbstractRequestException
```

✅ **CORRECT**: Prevents unintended subclassing.

---

## Best Practices Compliance

### ✅ PSR-12 Compliance

- Strict types ✅
- Proper namespace ✅
- Consistent formatting ✅
- Override attribute (PHP 8.3+) ✅

### ⚠️ SOLID Principles

1. **Single Responsibility**: ✅
2. **Open/Closed**: ✅
3. **Liskov Substitution**: ⚠️ Depends on details structure consistency
4. **Interface Segregation**: ✅
5. **Dependency Inversion**: ✅

---

## Critical Issues

### 🔴 Issue 1: Unexplained PHPStan Suppression

**Location**: Line 53
**Impact**: Type safety compromise
**Current**:
```php
// @phpstan-ignore-next-line argument.type
```

**Required Action**: Document why suppression is needed or fix the type issue.

**Investigation Steps**:
1. Verify `AbstractRequestException::new()` signature
2. Determine if nested array is intentional
3. Document JSON:API compliance if applicable

**Solution A** (if JSON:API intentional):
```php
/**
 * Create a new forbidden exception instance.
 *
 * Error details follow JSON:API error object specification where errors
 * are represented as an array of error objects, each containing status,
 * title, and detail fields.
 *
 * @see https://jsonapi.org/format/#error-objects
 */
public static function create(?string $detail = null): self
{
    // JSON:API error format uses array of error objects
    // PHPStan expects array<string, mixed> but we use array<int, array<string, string>>
    // @phpstan-ignore-next-line argument.type
    return self::new(ErrorCode::Forbidden, 'Forbidden', details: [
        [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => $detail ?? 'You are not authorized to perform this action.',
        ],
    ]);
}
```

**Solution B** (if consistency preferred):
```php
public static function create(?string $detail = null): self
{
    return self::new(
        ErrorCode::Forbidden,
        'Forbidden',
        details: [
            'status' => '403',
            'title' => 'Forbidden',
            'detail' => $detail ?? 'You are not authorized to perform this action.',
        ],
    );
}
```

---

## Major Issues

### 🟠 Issue 1: Potential Error Structure Inconsistency

**Location**: Lines 54-59
**Impact**: Client integration complexity

Verify if other HTTP exceptions (UnauthorizedException, ServerErrorException, etc.) use the same nested array structure. If not, standardize across all HTTP exceptions.

---

## Minor Issues

### 🟡 Issue 1: Redundant Override Parentheses

**Location**: Line 68
**Current**:
```php
#[Override()]
```

**Recommended**:
```php
#[Override]
```

Both are valid, but parentheses are unnecessary when the attribute has no parameters.

### 🟡 Issue 2: String vs Integer Status

**Location**: Line 56
**Observation**:
```php
'status' => '403',  // String
```

While `getStatusCode()` returns `int 403`.

**If JSON:API**: String is correct per spec.
**If not**: Consider consistency:
```php
'status' => 403,  // Integer
```

---

## Suggestions

### 🔵 Suggestion 1: Add Security Guidelines to DocBlock

Already detailed in Security Audit section.

### 🔵 Suggestion 2: Add Factory Variants for Common Scenarios

```php
/**
 * Create exception for resource ownership check failure.
 */
public static function notResourceOwner(?string $resourceType = null): self
{
    $detail = $resourceType !== null
        ? "You do not have permission to access this {$resourceType}"
        : 'You do not have permission to access this resource';

    return self::create($detail);
}

/**
 * Create exception for role/permission check failure.
 */
public static function insufficientPermissions(?string $action = null): self
{
    $detail = $action !== null
        ? "You do not have permission to {$action}"
        : 'You do not have permission to perform this action';

    return self::create($detail);
}

/**
 * Create exception for feature access restrictions.
 */
public static function featureNotAvailable(string $feature): self
{
    return self::create("Access to {$feature} is not available for your account");
}
```

Usage:
```php
if (!$resource->isOwnedBy($user)) {
    throw ForbiddenException::notResourceOwner('document');
}

if (!$user->can('delete', $resource)) {
    throw ForbiddenException::insufficientPermissions('delete this resource');
}
```

### 🔵 Suggestion 3: Add Helper to Check if Detail was Customized

```php
/**
 * Check if a custom detail message was provided.
 */
public function hasCustomDetail(): bool
{
    $details = $this->getErrorDetails();

    if (!isset($details[0]['detail'])) {
        return false;
    }

    return $details[0]['detail'] !== 'You are not authorized to perform this action.';
}
```

Useful for logging/monitoring:
```php
catch (ForbiddenException $e) {
    if ($e->hasCustomDetail()) {
        Log::info('Custom authorization failure', ['detail' => $e->getErrorMessage()]);
    }
}
```

### 🔵 Suggestion 4: Add Usage Examples

```php
/**
 * Exception for forbidden access errors (HTTP 403).
 *
 * ... [existing documentation] ...
 *
 * @example Basic usage
 * ```php
 * if (!Gate::allows('update', $post)) {
 *     throw ForbiddenException::create();
 * }
 * ```
 *
 * @example Custom detail
 * ```php
 * if (!$subscription->hasFeature('export')) {
 *     throw ForbiddenException::create(
 *         'Export feature requires a premium subscription'
 *     );
 * }
 * ```
 */
```

---

## Testing Recommendations

### Unit Tests Required

```php
<?php

namespace Tests\Unit\Exceptions;

use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Exceptions\AbstractRequestException;
use Cline\Forrst\Exceptions\ForbiddenException;
use Tests\TestCase;

final class ForbiddenExceptionTest extends TestCase
{
    /** @test */
    public function it_extends_abstract_request_exception(): void
    {
        $exception = ForbiddenException::create();

        $this->assertInstanceOf(AbstractRequestException::class, $exception);
    }

    /** @test */
    public function it_creates_with_default_message(): void
    {
        $exception = ForbiddenException::create();

        $this->assertSame('Forbidden', $exception->getErrorMessage());
        $this->assertSame(ErrorCode::Forbidden->value, $exception->getErrorCode());
    }

    /** @test */
    public function it_returns_403_status_code(): void
    {
        $exception = ForbiddenException::create();

        $this->assertSame(403, $exception->getStatusCode());
    }

    /** @test */
    public function it_creates_with_custom_detail(): void
    {
        $detail = 'You need admin role to perform this action';
        $exception = ForbiddenException::create($detail);

        $details = $exception->getErrorDetails();

        $this->assertIsArray($details);
        $this->assertArrayHasKey(0, $details);
        $this->assertSame($detail, $details[0]['detail']);
    }

    /** @test */
    public function it_has_structured_error_format(): void
    {
        $exception = ForbiddenException::create('Custom message');

        $details = $exception->getErrorDetails();

        $this->assertArrayHasKey(0, $details);
        $this->assertArrayHasKey('status', $details[0]);
        $this->assertArrayHasKey('title', $details[0]);
        $this->assertArrayHasKey('detail', $details[0]);

        $this->assertSame('403', $details[0]['status']);
        $this->assertSame('Forbidden', $details[0]['title']);
        $this->assertSame('Custom message', $details[0]['detail']);
    }

    /** @test */
    public function it_uses_default_detail_when_null_provided(): void
    {
        $exception = ForbiddenException::create(null);

        $details = $exception->getErrorDetails();

        $this->assertSame(
            'You are not authorized to perform this action.',
            $details[0]['detail']
        );
    }

    /** @test */
    public function it_converts_to_array_correctly(): void
    {
        $exception = ForbiddenException::create('Test detail');

        $array = $exception->toArray();

        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('details', $array);

        $this->assertSame(ErrorCode::Forbidden->value, $array['code']);
        $this->assertSame('Forbidden', $array['message']);
        $this->assertIsArray($array['details']);
    }

    /** @test */
    public function it_is_final_class(): void
    {
        $reflection = new \ReflectionClass(ForbiddenException::class);

        $this->assertTrue($reflection->isFinal());
    }

    /** @test */
    public function it_is_not_retryable(): void
    {
        $exception = ForbiddenException::create();

        $this->assertFalse($exception->isRetryable());
    }

    /** @test */
    public function status_code_override_annotation_is_present(): void
    {
        $reflection = new \ReflectionMethod(ForbiddenException::class, 'getStatusCode');
        $attributes = $reflection->getAttributes(\Override::class);

        $this->assertCount(1, $attributes);
    }
}
```

---

## Documentation Quality

**Current**: ⭐⭐⭐⭐ (4/5) - Very Good

The documentation clearly explains the purpose and provides good context about authentication vs. authorization.

**-1 Point**: Missing explanation of nested array structure and PHPStan suppression.

**Enhancement**: Add JSON:API reference and security guidelines.

---

## Summary

`ForbiddenException` is a functional HTTP 403 error implementation with good documentation, but it has architectural questions that need resolution. The nested array structure in details and the PHPStan suppression indicate either intentional JSON:API compliance or an inconsistency that should be addressed.

### Key Strengths
1. ✅ Clear distinction between 401 and 403 in documentation
2. ✅ Proper use of #[Override] attribute
3. ✅ Secure default error message
4. ✅ Final class prevents misuse
5. ✅ Correct HTTP status code override

### Improvement Opportunities
1. 🔴 Document or resolve PHPStan suppression
2. 🟠 Verify error structure consistency across HTTP exceptions
3. 🟡 Remove unnecessary parentheses from #[Override]
4. 🟡 Add security guidelines for custom detail messages
5. 🔵 Add usage examples to DocBlock

### Recommended Actions

**Priority 1 (Before Production)**:
1. Investigate and document PHPStan suppression
2. Verify error structure consistency across HTTP exceptions
3. Add security guidelines to prevent detailed error exposure

**Priority 2 (Next Sprint)**:
1. Remove redundant Override parentheses
2. Add usage examples
3. Consider factory method variants

**Priority 3 (Future Enhancement)**:
1. Add helper methods for common scenarios
2. Consider adding hasCustomDetail() method

---

**Reviewer**: Senior Code Review Architect
**Date**: 2025-12-23
**Recommendation**: 🟡 **CONDITIONAL APPROVE** - Resolve PHPStan suppression and verify structural consistency before production

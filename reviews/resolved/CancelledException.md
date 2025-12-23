# Code Review: CancelledException.php

**File**: `/Users/brian/Developer/cline/forrst/src/Exceptions/CancelledException.php`
**Type**: Concrete Exception Class
**Lines of Code**: 95
**Complexity**: Low-Medium
**Parent**: `AbstractRequestException`

---

## Executive Summary

`CancelledException` represents successful cancellation of a long-running operation through the Forrst cancellation extension. This exception stands out in the codebase by including accessor methods (`getToken()`, `getReason()`) beyond the standard factory pattern, providing rich API for handling cancelled operations. The implementation is well-documented, type-safe, and handles optional parameters effectively.

**Overall Assessment**: 🟢 **EXCELLENT** - Sophisticated implementation with strong accessor pattern.

---

## Architectural Analysis

### Design Pattern Implementation

**Pattern**: Factory Method + Accessor Pattern

```php
public static function create(?string $token = null, ?string $reason = null): self
{
    // Factory construction
}

public function getToken(): ?string { /* Accessor */ }
public function getReason(): ?string { /* Accessor */ }
```

✅ **EXCELLENT**: Combines factory construction with typed accessors for cancellation metadata.

### Inheritance Strategy

```
CancelledException → AbstractRequestException → Exception
```

**Direct Inheritance**: Unlike other cancellation exceptions that extend `NotFoundException`, this extends `AbstractRequestException` directly.

✅ **SEMANTICALLY CORRECT**: Cancellation is NOT a "not found" error—it's a successful state transition. The operation was found and successfully cancelled.

**Error Code Analysis**:
```php
ErrorCode::Cancelled
```

This maps to a distinct error code specifically for cancellations, separate from error scenarios.

### Accessor Methods - Unique in Codebase

**Innovation**:
```php
public function getToken(): ?string
{
    $token = $this->error->details['token'] ?? null;
    return is_string($token) ? $token : null;
}

public function getReason(): ?string
{
    $reason = $this->error->details['reason'] ?? null;
    return is_string($reason) ? $reason : null;
}
```

✅ **EXCELLENT DESIGN**: These accessor methods provide:
1. **Type Safety**: Validates that values are strings before returning
2. **Null Safety**: Returns null if key doesn't exist or value isn't a string
3. **Encapsulation**: Hides internal `error->details` array structure
4. **API Clarity**: Clear, documented methods vs. raw array access

**Comparison with Other Exceptions**:

Most exceptions in the codebase don't have accessors—they rely on `getErrorDetails()` returning the full details array. This exception provides a richer, more user-friendly API.

**Usage Difference**:
```php
// Without accessors (typical exceptions):
try {
    $operation->execute();
} catch (SomeException $e) {
    $details = $e->getErrorDetails();
    $value = $details['some_key'] ?? null; // No type safety
}

// With accessors (this exception):
try {
    $operation->execute();
} catch (CancelledException $e) {
    $token = $e->getToken(); // Guaranteed string|null
    $reason = $e->getReason(); // Guaranteed string|null
}
```

---

## Code Quality Evaluation

### Readability: ⭐⭐⭐⭐⭐ (5/5)

**Outstanding Documentation**:
```php
/**
 * Exception thrown when a request has been cancelled by the client.
 *
 * Part of the Forrst cancellation extension exceptions. Represents the CANCELLED
 * error code for requests that were cancelled via the cancellation extension.
 * Functions should throw this exception when they detect that cancellation has
 * been requested for the current operation.
 *
 * The cancellation extension allows clients to explicitly cancel long-running
 * operations by sending a cancel request with the associated cancellation token.
 * When a function detects cancellation, it should stop processing and throw this
 * exception to return a proper CANCELLED error response to the client.
 */
```

✅ **COMPREHENSIVE**: Explains not just WHAT the exception is, but HOW and WHEN to use it.

### Type Safety: ⭐⭐⭐⭐⭐ (5/5)

**Strict Typing Throughout**:
```php
<?php declare(strict_types=1);

use function is_string; // Explicit type checking import
```

**Type Validation in Accessors**:
```php
return is_string($token) ? $token : null;
```

✅ **DEFENSIVE**: Even though details are created internally, the accessors validate types, protecting against future refactoring mistakes.

### Naming Conventions: ⭐⭐⭐⭐⭐ (5/5)

- `create()`: Standard factory method ✅
- `getToken()`: Clear accessor following `get*` convention ✅
- `getReason()`: Clear accessor following `get*` convention ✅
- All parameter names are descriptive ✅

---

## Security Audit

### 🟡 Information Disclosure: Token Value in Details

**Current Implementation**:
```php
if ($token !== null) {
    $details['token'] = $token;
}
```

**Security Consideration**: The cancellation token is stored in error details and returned to clients.

**Risk Assessment**:

🟡 **Low-Medium Risk**:

Unlike `CancellationTokenNotFoundException` (where an *invalid* token is exposed), this exposes a *valid* token that successfully cancelled an operation.

**Scenarios**:
1. **Low Risk**: Token is single-use and immediately invalidated after cancellation
2. **Medium Risk**: Token could be reused or reveals patterns

**Mitigation**: Same as `CancellationTokenNotFoundException`—sanitize in production:

```php
public static function create(?string $token = null, ?string $reason = null): self
{
    $details = [];

    if ($token !== null) {
        // Sanitize token in production
        $details['token'] = app()->environment('production')
            ? self::sanitizeToken($token)
            : $token;
    }

    if ($reason !== null) {
        $details['reason'] = $reason;
    }

    return self::new(
        ErrorCode::Cancelled,
        $reason ?? 'Request was cancelled by client',
        details: $details !== [] ? $details : null,
    );
}

private static function sanitizeToken(string $token): string
{
    if (strlen($token) <= 8) {
        return '***';
    }
    return substr($token, 0, 4) . '***' . substr($token, -4);
}
```

### 🟢 No XSS/Injection Risks

The reason parameter could contain user-provided data:

```php
$reason ?? 'Request was cancelled by client'
```

✅ **SAFE**: The reason is stored as a string value and will be JSON-encoded when converted to array. No HTML rendering or code execution occurs.

**However**, ensure frontend applications properly escape when displaying:
```javascript
// Frontend code should escape:
const escaped = escapeHtml(error.details.reason);
```

---

## Performance Analysis

### 🟢 Optimal Factory Construction

**Conditional Details Building**:
```php
$details = [];

if ($token !== null) {
    $details['token'] = $token;
}

if ($reason !== null) {
    $details['reason'] = $reason;
}

return self::new(
    ErrorCode::Cancelled,
    $reason ?? 'Request was cancelled by client',
    details: $details !== [] ? $details : null,  // ✅ Smart: pass null instead of empty array
);
```

✅ **EXCELLENT**: The check `$details !== [] ? $details : null` prevents passing empty arrays, reducing memory footprint and JSON payload size.

### 🟢 Accessor Efficiency

**Type Checking Cost**:
```php
$token = $this->error->details['token'] ?? null;
return is_string($token) ? $token : null;
```

- **Array access**: O(1)
- **Type check**: O(1)
- **Total**: O(1) with negligible overhead

✅ **NO CONCERNS**: Accessor methods are extremely lightweight.

---

## Maintainability Assessment

### 🟢 Single Responsibility

The exception has three clear responsibilities:
1. Represent cancellation state
2. Store cancellation metadata (token, reason)
3. Provide typed access to metadata

✅ **COHESIVE**: All responsibilities are tightly related to cancellation handling.

### 🟢 Accessor Pattern Benefits

**Future-Proofing**:

If the internal structure changes (e.g., from `error->details` to a dedicated DTO), only the accessor methods need updating:

```php
// Future refactor example:
private CancellationMetadata $metadata;

public function getToken(): ?string
{
    return $this->metadata->token; // External API unchanged
}
```

✅ **EXCELLENT**: Encapsulation protects against future structural changes.

### 🟡 Default Reason Message Duplication

**Observation**:
```php
$reason ?? 'Request was cancelled by client'
```

The default message is only in the factory method. Consider extracting to a constant:

```php
final class CancelledException extends AbstractRequestException
{
    private const DEFAULT_REASON = 'Request was cancelled by client';

    public static function create(?string $token = null, ?string $reason = null): self
    {
        // ...
        return self::new(
            ErrorCode::Cancelled,
            $reason ?? self::DEFAULT_REASON,
            details: $details !== [] ? $details : null,
        );
    }
}
```

Benefits:
1. Reusable if other methods need the same message
2. Easier to update in one place
3. Can be overridden in tests

---

## Best Practices Compliance

### ✅ PSR-12 Compliance

- Strict types declaration ✅
- Proper namespace ✅
- Consistent formatting ✅
- Return type declarations ✅
- Explicit function imports (`use function is_string`) ✅

### ✅ SOLID Principles

1. **Single Responsibility**: ✅ Only represents cancellation
2. **Open/Closed**: ✅ Final class, closed for modification
3. **Liskov Substitution**: ✅ Can substitute AbstractRequestException
4. **Interface Segregation**: ✅ No interface bloat
5. **Dependency Inversion**: ✅ Depends on ErrorCode abstraction

### ✅ Exception Design Best Practices

1. **Factory Method**: ✅ Static constructor
2. **Accessor Methods**: ✅ Type-safe getters
3. **Immutability**: ✅ No mutable state
4. **Documentation**: ✅ Comprehensive docs
5. **Type Safety**: ✅ Runtime type validation

---

## Critical Issues

**None Found** ✅

---

## Major Issues

**None Found** ✅

---

## Minor Issues

### 🟡 Issue 1: Potential Token Exposure in Production

**Location**: Lines 54-56
**Impact**: Low-medium security risk
**Solution**: Already covered in Security Audit section (token sanitization)

### 🟡 Issue 2: Missing Validation for Empty Strings

**Location**: Line 50
**Current Behavior**:
```php
public static function create(?string $token = null, ?string $reason = null): self
{
    $details = [];

    if ($token !== null) {
        $details['token'] = $token;  // ← Accepts empty string ""
    }
}
```

**Issue**: Empty strings are treated as valid tokens/reasons:

```php
CancelledException::create('', ''); // Creates exception with empty strings
```

**Recommended Validation**:
```php
public static function create(?string $token = null, ?string $reason = null): self
{
    $details = [];

    if ($token !== null && $token !== '') {
        $details['token'] = $token;
    }

    if ($reason !== null && $reason !== '') {
        $details['reason'] = $reason;
    }

    return self::new(
        ErrorCode::Cancelled,
        ($reason !== null && $reason !== '') ? $reason : 'Request was cancelled by client',
        details: $details !== [] ? $details : null,
    );
}
```

Or even stricter:
```php
if ($token !== null) {
    $trimmedToken = trim($token);
    if ($trimmedToken !== '') {
        $details['token'] = $trimmedToken;
    }
}
```

---

## Suggestions

### 🔵 Suggestion 1: Add hasToken() and hasReason() Predicates

For cleaner checking logic:

```php
/**
 * Check if cancellation token is available.
 */
public function hasToken(): bool
{
    return $this->getToken() !== null;
}

/**
 * Check if cancellation reason is available.
 */
public function hasReason(): bool
{
    return $this->getReason() !== null;
}
```

Usage:
```php
if ($exception->hasToken()) {
    $this->logCancellation($exception->getToken());
}

// vs

if (($token = $exception->getToken()) !== null) {
    $this->logCancellation($token);
}
```

### 🔵 Suggestion 2: Add Named Constructors for Common Scenarios

```php
/**
 * Create exception for user-initiated cancellation.
 */
public static function byUser(?string $token = null): self
{
    return self::create($token, 'Request was cancelled by user');
}

/**
 * Create exception for timeout-based cancellation.
 */
public static function byTimeout(?string $token = null, int $timeoutSeconds = null): self
{
    $reason = $timeoutSeconds !== null
        ? "Request cancelled due to {$timeoutSeconds}s timeout"
        : 'Request cancelled due to timeout';

    return self::create($token, $reason);
}

/**
 * Create exception for system-initiated cancellation.
 */
public static function bySystem(?string $token = null, ?string $systemReason = null): self
{
    return self::create(
        $token,
        $systemReason ?? 'Request was cancelled by system'
    );
}
```

Usage:
```php
throw CancelledException::byUser($token);
throw CancelledException::byTimeout($token, 30);
throw CancelledException::bySystem($token, 'Server shutting down');
```

### 🔵 Suggestion 3: Add Metadata Object for Rich Cancellation Context

For more structured cancellation data:

```php
final class CancellationMetadata
{
    public function __construct(
        public readonly ?string $token = null,
        public readonly ?string $reason = null,
        public readonly ?\DateTimeImmutable $cancelledAt = null,
        public readonly ?string $cancelledBy = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'token' => $this->token,
            'reason' => $this->reason,
            'cancelled_at' => $this->cancelledAt?->toIso8601String(),
            'cancelled_by' => $this->cancelledBy,
        ]);
    }
}

public static function create(CancellationMetadata $metadata): self
{
    return self::new(
        ErrorCode::Cancelled,
        $metadata->reason ?? 'Request was cancelled by client',
        details: $metadata->toArray(),
    );
}

public function getMetadata(): CancellationMetadata
{
    return new CancellationMetadata(
        token: $this->getToken(),
        reason: $this->getReason(),
        cancelledAt: isset($this->error->details['cancelled_at'])
            ? new \DateTimeImmutable($this->error->details['cancelled_at'])
            : null,
        cancelledBy: $this->error->details['cancelled_by'] ?? null,
    );
}
```

### 🔵 Suggestion 4: Add Usage Examples to DocBlock

```php
/**
 * Exception thrown when a request has been cancelled by the client.
 *
 * ... [existing documentation] ...
 *
 * @example Basic cancellation
 * ```php
 * // In your long-running function:
 * if ($cancellationChecker->isCancelled()) {
 *     throw CancelledException::create($cancellationToken);
 * }
 * ```
 *
 * @example Cancellation with reason
 * ```php
 * throw CancelledException::create(
 *     token: $cancellationToken,
 *     reason: 'User requested cancellation after 30 seconds'
 * );
 * ```
 *
 * @example Handling in try-catch
 * ```php
 * try {
 *     $result = $operation->execute();
 * } catch (CancelledException $e) {
 *     $this->logger->info('Operation cancelled', [
 *         'token' => $e->getToken(),
 *         'reason' => $e->getReason(),
 *     ]);
 *
 *     return ['status' => 'cancelled'];
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
use Cline\Forrst\Exceptions\CancelledException;
use Tests\TestCase;

final class CancelledExceptionTest extends TestCase
{
    /** @test */
    public function it_extends_abstract_request_exception(): void
    {
        $exception = CancelledException::create();

        $this->assertInstanceOf(AbstractRequestException::class, $exception);
    }

    /** @test */
    public function it_creates_with_default_message(): void
    {
        $exception = CancelledException::create();

        $this->assertSame('Request was cancelled by client', $exception->getErrorMessage());
        $this->assertSame(ErrorCode::Cancelled->value, $exception->getErrorCode());
    }

    /** @test */
    public function it_creates_with_token(): void
    {
        $token = 'cancel-token-12345';
        $exception = CancelledException::create($token);

        $this->assertSame($token, $exception->getToken());
    }

    /** @test */
    public function it_creates_with_reason(): void
    {
        $reason = 'User cancelled after 10 seconds';
        $exception = CancelledException::create(reason: $reason);

        $this->assertSame($reason, $exception->getReason());
        $this->assertSame($reason, $exception->getErrorMessage());
    }

    /** @test */
    public function it_creates_with_token_and_reason(): void
    {
        $token = 'token-123';
        $reason = 'Custom cancellation reason';

        $exception = CancelledException::create($token, $reason);

        $this->assertSame($token, $exception->getToken());
        $this->assertSame($reason, $exception->getReason());
    }

    /** @test */
    public function it_returns_null_token_when_not_provided(): void
    {
        $exception = CancelledException::create();

        $this->assertNull($exception->getToken());
    }

    /** @test */
    public function it_returns_null_reason_when_not_provided(): void
    {
        $exception = CancelledException::create();

        $this->assertNull($exception->getReason());
    }

    /** @test */
    public function get_token_validates_type(): void
    {
        // Manually construct exception with non-string token (shouldn't happen in practice)
        $error = new ErrorData(
            code: ErrorCode::Cancelled,
            message: 'Test',
            details: ['token' => 123], // Invalid type
        );

        $exception = new CancelledException($error);

        $this->assertNull($exception->getToken()); // Should return null for non-string
    }

    /** @test */
    public function get_reason_validates_type(): void
    {
        $error = new ErrorData(
            code: ErrorCode::Cancelled,
            message: 'Test',
            details: ['reason' => ['array']], // Invalid type
        );

        $exception = new CancelledException($error);

        $this->assertNull($exception->getReason());
    }

    /** @test */
    public function it_includes_token_in_details(): void
    {
        $token = 'test-token';
        $exception = CancelledException::create($token);

        $details = $exception->getErrorDetails();
        $this->assertArrayHasKey('token', $details);
        $this->assertSame($token, $details['token']);
    }

    /** @test */
    public function it_includes_reason_in_details(): void
    {
        $reason = 'Test reason';
        $exception = CancelledException::create(reason: $reason);

        $details = $exception->getErrorDetails();
        $this->assertArrayHasKey('reason', $details);
        $this->assertSame($reason, $details['reason']);
    }

    /** @test */
    public function it_has_null_details_when_no_token_or_reason(): void
    {
        $exception = CancelledException::create();

        $this->assertNull($exception->getErrorDetails());
    }

    /** @test */
    public function it_converts_to_array_correctly(): void
    {
        $token = 'token-123';
        $reason = 'Test cancellation';

        $exception = CancelledException::create($token, $reason);
        $array = $exception->toArray();

        $this->assertArrayHasKey('code', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('details', $array);
        $this->assertSame(ErrorCode::Cancelled->value, $array['code']);
        $this->assertSame($reason, $array['message']);
        $this->assertSame($token, $array['details']['token']);
        $this->assertSame($reason, $array['details']['reason']);
    }

    /** @test */
    public function it_handles_empty_strings_as_values(): void
    {
        $exception = CancelledException::create('', '');

        // Empty strings should be stored (not filtered out)
        $details = $exception->getErrorDetails();
        $this->assertArrayHasKey('token', $details);
        $this->assertArrayHasKey('reason', $details);
        $this->assertSame('', $details['token']);
        $this->assertSame('', $details['reason']);
    }

    /** @test */
    public function it_is_final_class(): void
    {
        $reflection = new \ReflectionClass(CancelledException::class);

        $this->assertTrue($reflection->isFinal());
    }

    /** @test */
    public function it_is_not_retryable(): void
    {
        $exception = CancelledException::create();

        $this->assertFalse($exception->isRetryable());
    }
}
```

### Integration Tests Required

```php
<?php

namespace Tests\Feature\Exceptions;

use Cline\Forrst\Exceptions\CancelledException;
use Tests\TestCase;

final class CancellationExceptionIntegrationTest extends TestCase
{
    /** @test */
    public function it_returns_proper_json_response(): void
    {
        $exception = CancelledException::create('token-123', 'User cancelled');

        $response = response()->json(
            $exception->toArray(),
            $exception->getStatusCode()
        );

        $data = $response->getData(true);

        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('details', $data);
    }

    /** @test */
    public function cancellation_is_properly_handled_by_error_handler(): void
    {
        $this->postJson('/forrst/long-running-operation', ['operation' => 'test'])
            ->assertStatus(499); // Client Closed Request or custom cancellation status
    }
}
```

---

## Documentation Quality

**Current**: ⭐⭐⭐⭐⭐ (5/5) - Exceptional

The documentation is outstanding, providing:
- Clear purpose explanation
- Protocol context
- Usage guidance for function authors
- Detailed parameter descriptions

**Enhancement**: Add the usage examples from Suggestion 4.

---

## Summary

`CancelledException` is an exemplary implementation that goes beyond the standard exception pattern by providing rich accessor methods for cancellation metadata. The type-safe accessors, comprehensive documentation, and thoughtful API design make this exception a model for the rest of the codebase.

### Key Strengths
1. ✅ Rich accessor API (`getToken()`, `getReason()`)
2. ✅ Type-safe accessor implementation with runtime validation
3. ✅ Exceptional documentation with usage guidance
4. ✅ Smart null/empty array handling in factory
5. ✅ Proper semantic inheritance (not a "not found" error)
6. ✅ Final class prevents misuse

### Improvement Opportunities
1. 🟡 Sanitize token in production environments
2. 🟡 Validate empty strings in factory parameters
3. 🟡 Extract default reason message to constant
4. 🔵 Add `hasToken()` and `hasReason()` predicates
5. 🔵 Consider named constructors for common scenarios
6. 🔵 Add usage examples to DocBlock

### Recommended Actions

**Priority 1 (Before Production)**:
- Implement token sanitization for production

**Priority 2 (Next Sprint)**:
1. Add empty string validation
2. Extract default message to constant
3. Add `hasToken()`/`hasReason()` predicates

**Priority 3 (Future Enhancement)**:
1. Add named constructor variants
2. Add usage examples to DocBlock
3. Consider metadata object for rich context

---

**Reviewer**: Senior Code Review Architect
**Date**: 2025-12-23
**Recommendation**: ✅ **APPROVE WITH MINOR ENHANCEMENTS** - Excellent implementation, address token sanitization before production

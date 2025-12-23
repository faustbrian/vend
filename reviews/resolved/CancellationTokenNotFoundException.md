# Code Review: CancellationTokenNotFoundException.php

**File**: `/Users/brian/Developer/cline/forrst/src/Exceptions/CancellationTokenNotFoundException.php`
**Type**: Concrete Exception Class
**Lines of Code**: 49
**Complexity**: Low
**Parent**: `NotFoundException`

---

## Executive Summary

`CancellationTokenNotFoundException` handles the scenario where a client provides a cancellation token that doesn't exist in the server's registry. The implementation demonstrates good factory method naming (`forToken`), includes the invalid token in error details for debugging, and properly extends the `NotFoundException` hierarchy. This exception complements `CancellationTokenMissingException` by handling provided-but-invalid tokens versus missing tokens.

**Overall Assessment**: 🟢 **EXCELLENT** - Well-designed, production-ready implementation.

---

## Architectural Analysis

### Design Pattern Implementation

**Pattern**: Factory Method with Contextual Data Capture

```php
public static function forToken(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: ['token' => $token],
    );
}
```

✅ **EXCELLENT**: The `forToken()` factory method name clearly communicates its purpose and follows the "for*" naming convention common in exception factories.

**Method Naming**: `forToken()` is superior to generic `create()` because it:
1. Indicates what parameter is required (`token`)
2. Reads naturally at call site: `throw CancellationTokenNotFoundException::forToken($token)`
3. Follows conventional exception factory naming (e.g., `NotFoundHttpException::forUrl()`)

### Inheritance Chain Analysis

```
CancellationTokenNotFoundException → NotFoundException → AbstractRequestException → Exception
```

✅ **CORRECT HIERARCHY**: Extends `NotFoundException` which is semantically accurate since this represents a token lookup failure.

**Distinction from CancellationTokenMissingException**:

| Exception | Scenario | Token Parameter | HTTP Status |
|-----------|----------|-----------------|-------------|
| `CancellationTokenMissingException` | No token provided or empty string | Not present or `""` | 400/404 |
| `CancellationTokenNotFoundException` | Token provided but not found | Present but invalid | 404 |

✅ **CLEAR SEPARATION**: The two exceptions handle distinct error scenarios with no overlap.

### Error Code Reuse

**Both exceptions use**:
```php
code: ErrorCode::CancellationTokenUnknown,
```

🟡 **Minor Observation**: Shared error code

Both `CancellationTokenMissingException` and `CancellationTokenNotFoundException` use the same error code: `ErrorCode::CancellationTokenUnknown`.

**Analysis**:
- **Pro**: Simplifies client handling (one error code for all token issues)
- **Con**: Clients cannot programmatically distinguish missing vs. invalid tokens without parsing message

**Current Approach Acceptable If**:
- The Forrst protocol specification defines a single error code for all token errors
- Clients should handle both scenarios identically (e.g., request user to retry cancellation request)

**Alternative Approach** (if finer granularity needed):
```php
// CancellationTokenMissingException.php
code: ErrorCode::CancellationTokenMissing,

// CancellationTokenNotFoundException.php
code: ErrorCode::CancellationTokenNotFound,
```

**Recommendation**: Keep current approach unless protocol requires distinct error codes. The error message and details already provide sufficient differentiation for debugging.

---

## Code Quality Evaluation

### Readability: ⭐⭐⭐⭐⭐ (5/5)

**Exceptional Documentation**:
```php
/**
 * Exception thrown when a cancellation token is unknown or has expired.
 *
 * Part of the Forrst cancellation extension exceptions. Thrown when attempting to
 * cancel a request using a token that does not exist in the server's active token
 * registry. This may occur if the token was never issued, has already been consumed
 * by a previous cancellation, or has expired due to the associated request completing.
 */
```

✅ **COMPREHENSIVE**: Explains three distinct scenarios:
1. Token never issued (invalid from start)
2. Token already consumed (used for previous cancellation)
3. Token expired (request already completed)

This level of detail helps developers understand when this exception should be thrown.

**Parameter Documentation**:
```php
/**
 * @param  string $token The cancellation token that was not found or has expired.
 *                       Included in error details to help identify which token
 *                       the client attempted to use.
 */
```

✅ **CLEAR PURPOSE**: Explains why the parameter is captured (debugging).

### Type Safety: ⭐⭐⭐⭐⭐ (5/5)

```php
<?php declare(strict_types=1);
```

- Strict types enabled ✅
- String type for token parameter ✅
- Return type `self` declared ✅
- Final class prevents inheritance issues ✅
- ErrorCode enum provides type safety ✅

### Naming Conventions: ⭐⭐⭐⭐⭐ (5/5)

- Class name: Descriptive and follows `*NotFoundException` pattern ✅
- Method name: `forToken()` clearly indicates what's needed ✅
- Parameter name: `$token` is unambiguous ✅
- Error message: Clear and concise ✅

---

## Security Audit

### 🟡 Information Disclosure: Token Value Exposure

**Current Implementation**:
```php
return self::new(
    code: ErrorCode::CancellationTokenUnknown,
    message: 'Unknown cancellation token',
    details: ['token' => $token],
);
```

**Security Consideration**: The invalid token value is included in error details and returned to the client.

**Risk Analysis**:

✅ **Low Risk IF**:
- Cancellation tokens are random UUIDs or cryptographically secure strings
- Tokens are single-use and invalidated after lookup attempts
- Token format doesn't reveal internal system details

⚠️ **Medium Risk IF**:
- Tokens contain predictable patterns (e.g., sequential IDs)
- Tokens don't expire and can be brute-forced
- Token format reveals information (e.g., timestamp encoding)

**Recommendation**: Sanitize token in production environments:

```php
public static function forToken(string $token): self
{
    // In production, only return partial token for debugging
    $tokenDetails = app()->environment('production')
        ? ['token' => substr($token, 0, 8) . '...']
        : ['token' => $token];

    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: $tokenDetails,
    );
}
```

**Even Better**: Use a hash-based approach:
```php
public static function forToken(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: [
            'token_hash' => substr(hash('sha256', $token), 0, 16),
            'token_length' => strlen($token),
        ],
    );
}
```

This allows server-side correlation for debugging without exposing the actual token value.

### 🟢 No Injection Vulnerabilities

The token is passed through as string data, not executed or interpreted:

```php
details: ['token' => $token],
```

✅ **SECURE**: No SQL injection, code injection, or XSS risks since the token is only stored in an array for JSON serialization.

### 🟢 Error Message Safety

```php
message: 'Unknown cancellation token',
```

✅ **SECURE**: Generic message doesn't leak:
- Internal token storage mechanism
- Token validation logic
- Number of active tokens
- Server architecture details

---

## Performance Analysis

### 🟢 Optimal Construction

**Factory Method Efficiency**:
```php
public static function forToken(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: ['token' => $token],
    );
}
```

- **Time Complexity**: O(1) - Constant time object creation
- **Space Complexity**: O(1) - Single token string storage
- **No Heavy Operations**: No string manipulation, hashing, or I/O

✅ **NO CONCERNS**: Exception creation is fast and lightweight.

### 🟢 Memory Footprint

**Details Array**:
```php
details: ['token' => $token],
```

Memory usage = size of token string + array overhead (~100 bytes)

For typical UUID tokens (36 characters), total memory ~150 bytes.

✅ **MINIMAL**: No memory concerns even with high exception volumes.

---

## Maintainability Assessment

### 🟢 Single Responsibility

The exception has one clear purpose: represent an unknown/invalid cancellation token scenario.

✅ **EXCELLENT**: No mixed concerns.

### 🟢 Extensibility

**Final Class**:
```php
final class CancellationTokenNotFoundException extends NotFoundException
```

✅ **CORRECT**: Final modifier is appropriate because:
1. This is a leaf node in the exception hierarchy
2. No logical subclasses exist
3. Prevents fragile base class problems

### 🟢 Error Context

**Captured Information**:
```php
details: ['token' => $token],
```

✅ **SUFFICIENT**: Provides enough context for debugging without being verbose.

**Future Enhancement Opportunity**:

If more context is helpful, consider:

```php
public static function forToken(
    string $token,
    ?string $reason = null,
    ?array $additionalContext = null
): self {
    $details = ['token' => $token];

    if ($reason !== null) {
        $details['reason'] = $reason;
    }

    if ($additionalContext !== null) {
        $details = array_merge($details, $additionalContext);
    }

    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: $details,
    );
}
```

Usage:
```php
// Basic usage
throw CancellationTokenNotFoundException::forToken($token);

// With reason
throw CancellationTokenNotFoundException::forToken(
    $token,
    reason: 'Token expired after request completion'
);

// With additional context
throw CancellationTokenNotFoundException::forToken(
    $token,
    additionalContext: [
        'request_id' => $requestId,
        'checked_at' => now()->toIso8601String(),
    ]
);
```

---

## Best Practices Compliance

### ✅ PSR-12 Compliance

- Strict types declaration ✅
- Proper namespace ✅
- Consistent formatting ✅
- Return type declarations ✅
- Parameter type declarations ✅

### ✅ SOLID Principles

1. **Single Responsibility**: ✅ Only represents token not found scenario
2. **Open/Closed**: ✅ Final class, closed for modification
3. **Liskov Substitution**: ✅ Can substitute NotFoundException
4. **Interface Segregation**: ✅ No unnecessary interface pollution
5. **Dependency Inversion**: ✅ Depends on ErrorCode abstraction

### ✅ Exception Design Patterns

1. **Named Constructor**: ✅ `forToken()` is descriptive
2. **Immutability**: ✅ No mutable state
3. **Context Capture**: ✅ Invalid token included in details
4. **Appropriate Inheritance**: ✅ Extends NotFoundException
5. **Final Class**: ✅ Prevents misuse through subclassing

---

## Critical Issues

**None Found** ✅

---

## Major Issues

**None Found** ✅

---

## Minor Issues

### 🟡 Issue 1: Token Value Exposure in Error Details

**Location**: Line 45
**Impact**: Potential security concern in production
**Current**:
```php
details: ['token' => $token],
```

**Solution**: Sanitize token in production environments

```php
public static function forToken(string $token): self
{
    $details = ['token' => $token];

    // Sanitize token in production to prevent information leakage
    if (app()->environment('production')) {
        $details['token'] = self::sanitizeToken($token);
    }

    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: $details,
    );
}

/**
 * Sanitize token for safe logging in production.
 */
private static function sanitizeToken(string $token): string
{
    if (strlen($token) <= 8) {
        return '***';
    }

    return substr($token, 0, 4) . '***' . substr($token, -4);
}
```

Result:
- Development: `"token": "abc123-def456-ghi789"`
- Production: `"token": "abc1***i789"`

---

## Suggestions

### 🔵 Suggestion 1: Add Multiple Factory Methods for Different Scenarios

The class documentation mentions three scenarios. Consider explicit factory methods:

```php
/**
 * Create exception for a token that was never issued.
 */
public static function tokenNeverIssued(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: [
            'token' => $token,
            'reason' => 'never_issued',
        ],
    );
}

/**
 * Create exception for a token that has already been consumed.
 */
public static function tokenAlreadyConsumed(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Cancellation token has already been used',
        details: [
            'token' => $token,
            'reason' => 'already_consumed',
        ],
    );
}

/**
 * Create exception for a token whose associated request has completed.
 */
public static function tokenExpired(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Cancellation token has expired',
        details: [
            'token' => $token,
            'reason' => 'expired',
        ],
    );
}

/**
 * General factory method for unknown tokens.
 */
public static function forToken(string $token): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: ['token' => $token],
    );
}
```

Usage:
```php
// Specific scenarios with better error messages
throw CancellationTokenNotFoundException::tokenNeverIssued($token);
throw CancellationTokenNotFoundException::tokenAlreadyConsumed($token);
throw CancellationTokenNotFoundException::tokenExpired($token);

// Generic fallback
throw CancellationTokenNotFoundException::forToken($token);
```

Benefits:
1. More specific error messages for users
2. Structured reason codes for client-side handling
3. Better debugging capabilities

### 🔵 Suggestion 2: Add SourceData for Parameter Identification

Help clients identify which parameter had the issue:

```php
use Cline\Forrst\Data\Errors\SourceData;

public static function forToken(string $token, ?string $parameterName = 'token'): self
{
    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        source: new SourceData(parameter: $parameterName),
        details: ['token' => $token],
    );
}
```

JSON response includes:
```json
{
  "code": "CancellationTokenUnknown",
  "message": "Unknown cancellation token",
  "source": {
    "parameter": "token"
  },
  "details": {
    "token": "abc123..."
  }
}
```

### 🔵 Suggestion 3: Add Token Validation Helper

Prevent invalid token formats from being stored:

```php
/**
 * Validate token format before creating exception.
 *
 * @throws \InvalidArgumentException if token format is invalid
 */
public static function forToken(string $token): self
{
    if (trim($token) === '') {
        throw new \InvalidArgumentException(
            'Token cannot be empty. Use CancellationTokenMissingException instead.'
        );
    }

    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: ['token' => $token],
    );
}
```

This prevents misuse:
```php
// Invalid usage (should use MissingException):
throw CancellationTokenNotFoundException::forToken('');

// Correct usage:
throw CancellationTokenMissingException::create();
```

### 🔵 Suggestion 4: Add Logging Integration

For security monitoring:

```php
use Illuminate\Support\Facades\Log;

public static function forToken(string $token): self
{
    // Log unknown token attempts for security monitoring
    Log::warning('Unknown cancellation token attempted', [
        'token' => substr(hash('sha256', $token), 0, 16),
        'ip' => request()?->ip(),
        'user_agent' => request()?->userAgent(),
    ]);

    return self::new(
        code: ErrorCode::CancellationTokenUnknown,
        message: 'Unknown cancellation token',
        details: ['token' => $token],
    );
}
```

Benefits:
1. Security audit trail
2. Detect brute-force attempts
3. Identify suspicious patterns

---

## Testing Recommendations

### Unit Tests Required

```php
<?php

namespace Tests\Unit\Exceptions;

use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Exceptions\CancellationTokenNotFoundException;
use Cline\Forrst\Exceptions\NotFoundException;
use Tests\TestCase;

final class CancellationTokenNotFoundExceptionTest extends TestCase
{
    /** @test */
    public function it_extends_not_found_exception(): void
    {
        $exception = CancellationTokenNotFoundException::forToken('test-token');

        $this->assertInstanceOf(NotFoundException::class, $exception);
    }

    /** @test */
    public function it_creates_exception_with_token(): void
    {
        $token = 'abc123-def456-ghi789';
        $exception = CancellationTokenNotFoundException::forToken($token);

        $this->assertSame('Unknown cancellation token', $exception->getErrorMessage());
        $this->assertSame(
            ErrorCode::CancellationTokenUnknown->value,
            $exception->getErrorCode()
        );
    }

    /** @test */
    public function it_includes_token_in_error_details(): void
    {
        $token = 'test-token-12345';
        $exception = CancellationTokenNotFoundException::forToken($token);

        $details = $exception->getErrorDetails();

        $this->assertIsArray($details);
        $this->assertArrayHasKey('token', $details);
        $this->assertSame($token, $details['token']);
    }

    /** @test */
    public function it_includes_token_in_array_representation(): void
    {
        $token = 'test-token-uuid';
        $exception = CancellationTokenNotFoundException::forToken($token);

        $array = $exception->toArray();

        $this->assertArrayHasKey('details', $array);
        $this->assertArrayHasKey('token', $array['details']);
        $this->assertSame($token, $array['details']['token']);
    }

    /** @test */
    public function it_handles_empty_string_token(): void
    {
        // Edge case: empty string should probably use MissingException instead,
        // but if used, should not crash
        $exception = CancellationTokenNotFoundException::forToken('');

        $this->assertSame('', $exception->getErrorDetails()['token']);
    }

    /** @test */
    public function it_handles_very_long_tokens(): void
    {
        $longToken = str_repeat('a', 1000);
        $exception = CancellationTokenNotFoundException::forToken($longToken);

        $this->assertSame($longToken, $exception->getErrorDetails()['token']);
    }

    /** @test */
    public function it_handles_tokens_with_special_characters(): void
    {
        $token = 'token-with-special!@#$%^&*()chars';
        $exception = CancellationTokenNotFoundException::forToken($token);

        $this->assertSame($token, $exception->getErrorDetails()['token']);
    }

    /** @test */
    public function it_is_not_retryable(): void
    {
        $exception = CancellationTokenNotFoundException::forToken('token');

        $this->assertFalse(
            $exception->isRetryable(),
            'Token not found is a permanent error'
        );
    }

    /** @test */
    public function it_returns_appropriate_http_status_code(): void
    {
        $exception = CancellationTokenNotFoundException::forToken('token');

        $this->assertSame(404, $exception->getStatusCode());
    }

    /** @test */
    public function it_is_final_class(): void
    {
        $reflection = new \ReflectionClass(CancellationTokenNotFoundException::class);

        $this->assertTrue($reflection->isFinal());
    }
}
```

### Integration Tests Required

```php
<?php

namespace Tests\Feature\Exceptions;

use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Exceptions\CancellationTokenNotFoundException;
use Tests\TestCase;

final class CancellationTokenExceptionIntegrationTest extends TestCase
{
    /** @test */
    public function it_returns_proper_json_response_for_unknown_token(): void
    {
        $token = 'invalid-token-12345';
        $exception = CancellationTokenNotFoundException::forToken($token);

        $response = response()->json(
            $exception->toArray(),
            $exception->getStatusCode(),
            $exception->getHeaders()
        );

        $response->assertStatus(404);
        $response->assertJson([
            'code' => ErrorCode::CancellationTokenUnknown->value,
            'message' => 'Unknown cancellation token',
            'details' => [
                'token' => $token,
            ],
        ]);
    }

    /** @test */
    public function cancellation_endpoint_returns_not_found_for_invalid_token(): void
    {
        $this->postJson('/forrst/cancel', ['token' => 'invalid-token'])
            ->assertStatus(404)
            ->assertJson([
                'code' => ErrorCode::CancellationTokenUnknown->value,
            ]);
    }

    /** @test */
    public function multiple_unknown_token_attempts_are_logged(): void
    {
        Log::spy();

        CancellationTokenNotFoundException::forToken('token-1');
        CancellationTokenNotFoundException::forToken('token-2');
        CancellationTokenNotFoundException::forToken('token-3');

        // Verify security logging if implemented
        Log::shouldHaveReceived('warning')
            ->times(3)
            ->with('Unknown cancellation token attempted', Mockery::any());
    }
}
```

---

## Documentation Quality

**Current**: ⭐⭐⭐⭐⭐ (5/5) - Exceptional

The documentation is comprehensive, explaining:
- Purpose and context
- Three distinct scenarios when exception is thrown
- Parameter purpose
- Relationship to Forrst protocol

**Enhancement**: Add usage example:

```php
/**
 * Exception thrown when a cancellation token is unknown or has expired.
 *
 * Part of the Forrst cancellation extension exceptions. Thrown when attempting to
 * cancel a request using a token that does not exist in the server's active token
 * registry. This may occur if the token was never issued, has already been consumed
 * by a previous cancellation, or has expired due to the associated request completing.
 *
 * @example
 * ```php
 * $token = $request->input('token');
 *
 * if (!$tokenRegistry->exists($token)) {
 *     throw CancellationTokenNotFoundException::forToken($token);
 * }
 *
 * $tokenRegistry->cancel($token);
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/extensions/cancellation
 */
```

---

## Comparison with Related Exception

### CancellationTokenMissingException vs CancellationTokenNotFoundException

| Aspect | Missing | NotFound |
|--------|---------|----------|
| **Factory Method** | `create()` (generic) | `forToken()` (specific) ✅ |
| **Required Param** | None | `string $token` ✅ |
| **Error Message** | "required" | "unknown" ✅ |
| **Details** | None | Includes token ✅ |
| **Usage** | No token provided | Token provided but invalid |

**Consistency Recommendation**: Align `CancellationTokenMissingException` to use `missing()` method for consistency with `forToken()` pattern.

---

## Summary

`CancellationTokenNotFoundException` is an exceptionally well-implemented exception class that serves as a model for domain-specific exception design. The `forToken()` naming convention is superior to generic `create()` methods, the documentation is comprehensive, and the inclusion of the invalid token in error details aids debugging while maintaining security through careful handling.

### Key Strengths
1. ✅ Excellent factory method naming (`forToken`)
2. ✅ Captures essential context (invalid token value)
3. ✅ Comprehensive documentation with scenario examples
4. ✅ Proper inheritance hierarchy
5. ✅ Type-safe implementation
6. ✅ Final class prevents misuse
7. ✅ Clear distinction from CancellationTokenMissingException

### Improvement Opportunities
1. 🟡 Sanitize token value in production environments
2. 🔵 Consider specific factory methods for different scenarios
3. 🔵 Add SourceData for parameter identification
4. 🔵 Add token validation to prevent misuse
5. 🔵 Consider security logging for monitoring

### Recommended Actions

**Priority 1 (Before Production)**:
- Implement token sanitization in production (security)

**Priority 2 (Next Sprint)**:
1. Add SourceData with parameter name
2. Consider specific factory methods for different scenarios
3. Add token validation guard

**Priority 3 (Future Enhancement)**:
1. Add security logging integration
2. Add usage examples to DocBlock
3. Align CancellationTokenMissingException naming for consistency

---

**Reviewer**: Senior Code Review Architect
**Date**: 2025-12-23
**Recommendation**: ✅ **APPROVE WITH CAVEAT** - Address token sanitization before production deployment

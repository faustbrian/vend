# Code Review: RequestValidated.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Events/RequestValidated.php`

**Purpose:** Event dispatched after request parsing and protocol validation, representing the earliest point for extension inspection and early-stage validation before function resolution.

---

## Executive Summary

The `RequestValidated` event is a clean, minimal implementation that properly extends `ExtensionEvent`. It serves its purpose as an early lifecycle hook effectively. While production-ready, the simplicity reveals opportunities for enhanced functionality around validation context, rejection reasons, and early-stage request transformation capabilities.

**Strengths:**
- Clean, minimal design appropriate for its purpose
- Proper extension of ExtensionEvent base class
- Clear documentation of use cases
- Correct use of `final` keyword
- Simple, easy to understand

**Areas for Improvement:**
- Missing validation context (what was validated, what passed/failed)
- No helper methods for common early-stage validations
- Lacks rejection reason tracking
- Could benefit from validation metadata
- No request transformation helpers

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) - EXCELLENT
The class has a single, focused responsibility: representing the post-validation lifecycle event with request data access.

**Score: 10/10**

### Open/Closed Principle (OCP) - EXCELLENT
The `final` class is intentionally closed for extension (correct for events). Open for behavior extension through listeners.

**Score: 10/10**

### Liskov Substitution Principle (LSP) - EXCELLENT
Properly substitutable for `ExtensionEvent` base class with no contract violations.

**Score: 10/10**

### Interface Segregation Principle (ISP) - EXCELLENT
Minimal interface providing exactly what's needed: request data access and inherited event propagation control.

**Score: 10/10**

### Dependency Inversion Principle (DIP) - EXCELLENT
Depends on `RequestObjectData` abstraction, not concrete implementations.

**Score: 10/10**

---

## Code Quality Issues

### 🟡 Minor Issue #1: No Validation Context

**Issue:** The event doesn't provide any context about what validation was performed or what passed. Extensions can't tell if specific validations succeeded or if certain fields were checked.

**Location:** Entire file (missing functionality)

**Impact:**
- Extensions can't build on existing validation
- Duplicate validation across extensions
- No visibility into what was already checked
- Difficult to provide specific error messages

**Solution:**
```php
// Add to RequestValidated.php after the constructor (line 46):

/**
 * Validation context from the protocol validation phase.
 *
 * @var array{
 *     validated_fields: array<string>,
 *     validation_rules: array<string, array<string>>,
 *     passed_checks: array<string>
 * }
 */
private array $validationContext;

/**
 * Create a new request validated event instance.
 *
 * @param RequestObjectData $request The validated request object that passed protocol
 *                                   schema validation. Contains parsed function name,
 *                                   arguments, protocol version, extension options, and
 *                                   request metadata. Extensions can inspect this data
 *                                   to perform early authorization, rate limiting, or
 *                                   custom validation before function resolution.
 * @param array{validated_fields: array<string>, validation_rules: array<string, array<string>>, passed_checks: array<string>} $validationContext
 *                                   Context about what was validated
 */
public function __construct(
    RequestObjectData $request,
    array $validationContext = [],
) {
    parent::__construct($request);
    $this->validationContext = $validationContext;
}

/**
 * Get the validation context.
 *
 * Returns information about what was validated during protocol
 * validation, allowing extensions to avoid duplicate checks.
 *
 * @return array{validated_fields: array<string>, validation_rules: array<string, array<string>>, passed_checks: array<string>}
 */
public function getValidationContext(): array
{
    return $this->validationContext;
}

/**
 * Check if a specific field was validated.
 *
 * @param string $field Field name to check
 * @return bool True if field was validated
 */
public function wasFieldValidated(string $field): bool
{
    return in_array($field, $this->validationContext['validated_fields'] ?? [], true);
}

/**
 * Check if a specific validation check passed.
 *
 * @param string $checkName Name of the validation check
 * @return bool True if check passed
 */
public function didCheckPass(string $checkName): bool
{
    return in_array($checkName, $this->validationContext['passed_checks'] ?? [], true);
}
```

**Usage:**
```php
class CustomValidationListener
{
    public function handle(RequestValidated $event): void
    {
        // Skip if already validated
        if ($event->wasFieldValidated('custom_field')) {
            return;
        }

        // Perform custom validation
        if (!$this->validateCustomField($event->request)) {
            $event->rejectRequest('custom_field_invalid', 'Custom field validation failed');
        }
    }
}
```

### 🟡 Minor Issue #2: No Request Rejection Helpers

**Issue:** While the event can short-circuit execution via `stopPropagation()` and `setResponse()`, there's no convenient method specifically for rejecting requests with error responses.

**Location:** Entire file (missing convenience methods)

**Impact:**
- Verbose error response creation in every listener
- Inconsistent error response formats
- No centralized rejection logic

**Solution:**
```php
// Add to RequestValidated.php:

/**
 * Reject the request with an error response.
 *
 * Convenience method for early-stage validation failures. Creates
 * an error response and stops propagation, preventing further processing.
 *
 * @param string $errorCode Error code (use ErrorCode enum values)
 * @param string $message Human-readable error message
 * @param array<string, mixed> $metadata Additional error metadata
 * @return void
 */
public function rejectRequest(
    string $errorCode,
    string $message,
    array $metadata = []
): void {
    $errorResponse = new ResponseData([
        'error' => [
            'code' => $errorCode,
            'message' => $message,
            'metadata' => $metadata,
        ],
    ]);

    $this->setResponse($errorResponse);
    $this->stopPropagation();
}

/**
 * Reject the request due to authorization failure.
 *
 * Specialized rejection for authorization/authentication failures.
 * Sets appropriate error code and stops processing.
 *
 * @param string $reason Reason for authorization failure
 * @return void
 */
public function rejectUnauthorized(string $reason = 'Authorization required'): void
{
    $this->rejectRequest(
        ErrorCode::Unauthorized->value,
        $reason,
        ['requested_function' => $this->request->function ?? 'unknown']
    );
}

/**
 * Reject the request due to rate limiting.
 *
 * Specialized rejection for rate limit violations. Includes
 * retry-after metadata when provided.
 *
 * @param null|int $retryAfter Seconds until client can retry
 * @param string $message Custom rate limit message
 * @return void
 */
public function rejectRateLimited(?int $retryAfter = null, string $message = 'Rate limit exceeded'): void
{
    $metadata = [];
    if ($retryAfter !== null) {
        $metadata['retry_after'] = $retryAfter;
    }

    $this->rejectRequest(
        ErrorCode::RateLimited->value,
        $message,
        $metadata
    );
}

/**
 * Check if the request has been rejected.
 *
 * Returns true if a rejection method was called or if propagation
 * was stopped with an error response.
 *
 * @return bool True if request was rejected
 */
public function isRejected(): bool
{
    if (!$this->isPropagationStopped()) {
        return false;
    }

    $response = $this->getResponse();
    return $response !== null && isset($response->error);
}
```

**Usage:**
```php
// Instead of:
if (!$this->authorize($event->request)) {
    $event->setResponse(new ResponseData([
        'error' => [
            'code' => 'UNAUTHORIZED',
            'message' => 'Authorization required',
        ],
    ]));
    $event->stopPropagation();
}

// Use:
if (!$this->authorize($event->request)) {
    $event->rejectUnauthorized();
}

// Or with custom reason:
if (!$this->hasPermission($event->request->function)) {
    $event->rejectUnauthorized('Insufficient permissions for this function');
}
```

### 🔵 Suggestion #1: Add Request Transformation Helpers

**Issue:** Extensions may need to transform or sanitize request data before execution. No helper methods for this common operation.

**Location:** Entire file (enhancement)

**Impact:** Limited ability to modify requests in early stage

**Solution:**
```php
// Add to RequestValidated.php:

/**
 * Transformed request (if transformation occurred).
 *
 * @var null|RequestObjectData
 */
private ?RequestObjectData $transformedRequest = null;

/**
 * Transform the request using a callback.
 *
 * Allows extensions to modify the request before function resolution.
 * Useful for request sanitization, normalization, or enhancement.
 *
 * @param callable(RequestObjectData): RequestObjectData $transformer
 * @return void
 */
public function transformRequest(callable $transformer): void
{
    $currentRequest = $this->transformedRequest ?? $this->request;
    $this->transformedRequest = $transformer($currentRequest);
}

/**
 * Get the current request (original or transformed).
 *
 * Returns the transformed request if transformation occurred,
 * otherwise returns the original request.
 *
 * @return RequestObjectData Current request
 */
public function getCurrentRequest(): RequestObjectData
{
    return $this->transformedRequest ?? $this->request;
}

/**
 * Check if the request has been transformed.
 *
 * @return bool True if transformation occurred
 */
public function isRequestTransformed(): bool
{
    return $this->transformedRequest !== null;
}

/**
 * Get the original untransformed request.
 *
 * @return RequestObjectData Original request
 */
public function getOriginalRequest(): RequestObjectData
{
    return $this->request;
}
```

**Usage:**
```php
class SanitizationListener
{
    public function handle(RequestValidated $event): void
    {
        // Sanitize request arguments
        $event->transformRequest(function (RequestObjectData $request) {
            $sanitized = clone $request;
            $sanitized->arguments = $this->sanitizeArguments($request->arguments);
            return $sanitized;
        });
    }
}
```

### 🔵 Suggestion #2: Add Early-Stage Metrics Support

**Issue:** No built-in support for tracking early-stage validation metrics like validation time, rejection reasons, etc.

**Location:** Entire file (enhancement)

**Impact:** Limited observability into early-stage request processing

**Solution:**
```php
// Add to RequestValidated.php:

/**
 * Metrics collected during early-stage processing.
 *
 * @var array{validation_time_ms: float, listeners_executed: int, rejection_reason: ?string}
 */
private array $metrics;

/**
 * Create a new request validated event instance.
 *
 * @param RequestObjectData $request The validated request object
 * @param array{validated_fields: array<string>, validation_rules: array<string, array<string>>, passed_checks: array<string>} $validationContext
 * @param float $validationTimeMs Time spent on protocol validation in milliseconds
 */
public function __construct(
    RequestObjectData $request,
    array $validationContext = [],
    float $validationTimeMs = 0.0,
) {
    parent::__construct($request);
    $this->validationContext = $validationContext;
    $this->metrics = [
        'validation_time_ms' => $validationTimeMs,
        'listeners_executed' => 0,
        'rejection_reason' => null,
        'created_at' => microtime(true),
    ];
}

/**
 * Get metrics about early-stage processing.
 *
 * @return array{validation_time_ms: float, listeners_executed: int, rejection_reason: ?string, total_time_ms: float}
 */
public function getMetrics(): array
{
    $this->metrics['total_time_ms'] = (microtime(true) - $this->metrics['created_at']) * 1000;
    return $this->metrics;
}

/**
 * Increment the listener execution count.
 *
 * Should be called by event dispatcher after each listener executes.
 *
 * @return void
 */
public function incrementListenerCount(): void
{
    $this->metrics['listeners_executed']++;
}

// Update rejectRequest() to track rejection reason:
public function rejectRequest(
    string $errorCode,
    string $message,
    array $metadata = []
): void {
    $this->metrics['rejection_reason'] = $errorCode;

    $errorResponse = new ResponseData([
        'error' => [
            'code' => $errorCode,
            'message' => $message,
            'metadata' => $metadata,
        ],
    ]);

    $this->setResponse($errorResponse);
    $this->stopPropagation();
}
```

---

## Security Vulnerabilities

### No Direct Security Issues

The event class itself doesn't introduce security vulnerabilities. However, security is a primary use case for this event:

### 🟢 Security Best Practice: Ideal for Auth/Rate Limiting

**Observation:** This event is the perfect place for authentication, authorization, and rate limiting checks.

**Location:** Event usage in listeners

**Recommendation:**
```php
// Recommended listener priority order for security:

// 1. Authentication (highest priority)
class AuthenticationListener
{
    public function handle(RequestValidated $event): void
    {
        $authHeader = $event->request->metadata['Authorization'] ?? null;

        if (!$authHeader) {
            $event->rejectUnauthorized('Authentication required');
            return;
        }

        $user = $this->authenticateUser($authHeader);
        if (!$user) {
            $event->rejectUnauthorized('Invalid credentials');
            return;
        }

        // Store authenticated user in request context
        $event->request->setAuthenticatedUser($user);
    }
}

// 2. Authorization
class AuthorizationListener
{
    public function handle(RequestValidated $event): void
    {
        $user = $event->request->getAuthenticatedUser();
        $function = $event->request->function;

        if (!$this->canAccess($user, $function)) {
            $event->rejectRequest(
                ErrorCode::Forbidden->value,
                "You do not have permission to access function: {$function}"
            );
        }
    }
}

// 3. Rate Limiting
class RateLimitListener
{
    public function handle(RequestValidated $event): void
    {
        $user = $event->request->getAuthenticatedUser();
        $key = "rate_limit:{$user->id}:{$event->request->function}";

        if ($this->rateLimiter->tooManyAttempts($key, $maxAttempts = 60)) {
            $retryAfter = $this->rateLimiter->availableIn($key);
            $event->rejectRateLimited($retryAfter);
        }

        $this->rateLimiter->hit($key, $decayMinutes = 1);
    }
}
```

---

## Performance Concerns

### Excellent Performance Profile

**No performance issues.** The event has minimal overhead:

1. **Object Creation:** Single allocation, minimal properties
2. **Property Access:** Direct O(1) access
3. **Memory:** ~256-512 bytes per instance
4. **Early Rejection:** Can prevent expensive function execution

**Performance Benefits:**
- Early-stage rejection prevents wasted resources
- Rate limiting at this stage prevents downstream overload
- Authentication here avoids unnecessary function resolution

---

## Maintainability Assessment

### Excellent Maintainability - Score: 9.0/10

**Strengths:**
1. Simple, clean implementation
2. Clear documentation of purpose and use cases
3. Proper inheritance from ExtensionEvent
4. Easy to understand and extend via listeners
5. Focused responsibility

**Minor Weaknesses:**
1. Very minimal - could benefit from convenience methods
2. No validation context or metrics tracking

**Testing Recommendations:**

```php
// tests/Unit/Events/RequestValidatedTest.php

namespace Tests\Unit\Events;

use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Events\RequestValidated;
use PHPUnit\Framework\TestCase;

final class RequestValidatedTest extends TestCase
{
    /** @test */
    public function it_stores_validated_request(): void
    {
        $request = $this->createMock(RequestObjectData::class);
        $event = new RequestValidated($request);

        $this->assertSame($request, $event->request);
    }

    /** @test */
    public function it_can_reject_request_with_error(): void
    {
        $event = new RequestValidated($this->createMock(RequestObjectData::class));

        $event->rejectRequest('CUSTOM_ERROR', 'Custom error message', ['key' => 'value']);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertTrue($event->isRejected());

        $response = $event->getResponse();
        $this->assertSame('CUSTOM_ERROR', $response->error['code']);
        $this->assertSame('Custom error message', $response->error['message']);
    }

    /** @test */
    public function it_can_reject_unauthorized_requests(): void
    {
        $event = new RequestValidated($this->createMock(RequestObjectData::class));

        $event->rejectUnauthorized('Custom auth message');

        $this->assertTrue($event->isRejected());
        $response = $event->getResponse();
        $this->assertSame(ErrorCode::Unauthorized->value, $response->error['code']);
    }

    /** @test */
    public function it_can_reject_rate_limited_requests(): void
    {
        $event = new RequestValidated($this->createMock(RequestObjectData::class));

        $event->rejectRateLimited(60, 'Custom rate limit message');

        $this->assertTrue($event->isRejected());
        $response = $event->getResponse();
        $this->assertSame(ErrorCode::RateLimited->value, $response->error['code']);
        $this->assertSame(60, $response->error['metadata']['retry_after']);
    }

    /** @test */
    public function is_rejected_returns_false_when_not_rejected(): void
    {
        $event = new RequestValidated($this->createMock(RequestObjectData::class));

        $this->assertFalse($event->isRejected());
    }

    /** @test */
    public function it_tracks_validation_context(): void
    {
        $context = [
            'validated_fields' => ['function', 'arguments'],
            'validation_rules' => ['function' => ['required', 'string']],
            'passed_checks' => ['protocol_version', 'schema'],
        ];

        $event = new RequestValidated($this->createMock(RequestObjectData::class), $context);

        $this->assertSame($context, $event->getValidationContext());
        $this->assertTrue($event->wasFieldValidated('function'));
        $this->assertTrue($event->didCheckPass('protocol_version'));
        $this->assertFalse($event->wasFieldValidated('nonexistent'));
    }

    /** @test */
    public function it_supports_request_transformation(): void
    {
        $original = $this->createMock(RequestObjectData::class);
        $event = new RequestValidated($original);

        $this->assertFalse($event->isRequestTransformed());

        $transformed = $this->createMock(RequestObjectData::class);
        $event->transformRequest(fn($req) => $transformed);

        $this->assertTrue($event->isRequestTransformed());
        $this->assertSame($transformed, $event->getCurrentRequest());
        $this->assertSame($original, $event->getOriginalRequest());
    }

    /** @test */
    public function it_tracks_processing_metrics(): void
    {
        $event = new RequestValidated(
            $this->createMock(RequestObjectData::class),
            [],
            validationTimeMs: 12.5
        );

        $metrics = $event->getMetrics();

        $this->assertSame(12.5, $metrics['validation_time_ms']);
        $this->assertSame(0, $metrics['listeners_executed']);
        $this->assertNull($metrics['rejection_reason']);

        $event->incrementListenerCount();
        $event->rejectUnauthorized();

        $metrics = $event->getMetrics();
        $this->assertSame(1, $metrics['listeners_executed']);
        $this->assertSame(ErrorCode::Unauthorized->value, $metrics['rejection_reason']);
    }

    /** @test */
    public function it_is_final_and_cannot_be_extended(): void
    {
        $reflection = new \ReflectionClass(RequestValidated::class);

        $this->assertTrue($reflection->isFinal());
    }

    /** @test */
    public function readonly_properties_cannot_be_modified(): void
    {
        $event = new RequestValidated($this->createMock(RequestObjectData::class));

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $event->request = $this->createMock(RequestObjectData::class);
    }
}
```

---

## Additional Recommendations

### 1. Document Recommended Listener Priority

```markdown
<!-- docs/events/request-validated-priority.md -->

# RequestValidated Event Listener Priority

The `RequestValidated` event is dispatched early in the request lifecycle, making it ideal for authentication, authorization, and rate limiting. Listeners should be ordered by priority:

## Priority Order (Highest to Lowest)

### 1. Authentication (Priority: 100)
Verify client credentials and establish identity.

### 2. Authorization (Priority: 90)
Check if authenticated client has permission for the requested function.

### 3. Rate Limiting (Priority: 80)
Prevent abuse by limiting request rate per client/function.

### 4. Request Sanitization (Priority: 70)
Clean and normalize request data.

### 5. Custom Validation (Priority: 50)
Application-specific validation rules.

### 6. Logging/Metrics (Priority: 10)
Record request for audit trail or metrics.

## Example Configuration

```php
// In EventServiceProvider:
protected $listen = [
    RequestValidated::class => [
        AuthenticationListener::class . '@handle:100',
        AuthorizationListener::class . '@handle:90',
        RateLimitListener::class . '@handle:80',
        RequestSanitizationListener::class . '@handle:70',
        CustomValidationListener::class . '@handle:50',
        RequestLoggingListener::class . '@handle:10',
    ],
];
```
```

---

## Conclusion

The `RequestValidated` event is a clean, focused implementation that serves its purpose well as an early lifecycle hook. While simple, it provides the foundation for critical security and validation workflows. The suggested enhancements would improve developer experience and observability but are not required for production use.

**Final Score: 9.0/10**

**Strengths:**
- Clean, minimal design
- Perfect for authentication/authorization/rate limiting
- Proper SOLID principles adherence
- Production-ready implementation

**Suggested Improvements (All Minor):**
1. Add request rejection helpers (Minor Issue #2) - **Priority: MEDIUM**
2. Add validation context tracking (Minor Issue #1) - **Priority: LOW**
3. Add request transformation support (Suggestion #1) - **Priority: LOW**
4. Add metrics tracking (Suggestion #2) - **Priority: LOW**

**Recommended Next Steps:**
1. Add rejection helper methods for common use cases (Minor Issue #2) - **Priority: MEDIUM**
2. Document recommended listener priority for security (High Priority) - **Priority: HIGH**
3. Create comprehensive test suite - **Priority: MEDIUM**
4. Add validation context and metrics (Minor Issue #1, Suggestion #2) - **Priority: LOW**

**Overall Assessment:** Excellent minimalist implementation. The rejection helpers (Minor Issue #2) would significantly improve developer experience and should be prioritized. All other improvements are optional enhancements.

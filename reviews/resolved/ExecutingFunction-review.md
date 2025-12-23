# Code Review: ExecutingFunction.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Events/ExecutingFunction.php`

**Purpose:** Event dispatched immediately before function execution begins, providing the last opportunity for extensions to intercept and short-circuit execution.

---

## Executive Summary

The `ExecutingFunction` event is a clean, well-designed implementation that properly extends the `ExtensionEvent` base class. It follows Laravel event conventions and provides appropriate access to request and extension data for pre-execution interception. The code is production-ready with minor opportunities for enhancement around immutability guarantees and helper methods.

**Strengths:**
- Clean inheritance from ExtensionEvent base class
- Appropriate use of `final` keyword to prevent further extension
- Clear, comprehensive documentation
- Proper readonly property usage for immutability
- Correct constructor parameter promotion

**Areas for Improvement:**
- Potential for helper methods to simplify common use cases
- Missing examples in documentation
- No built-in support for common patterns (cache lookup, rate limiting)
- Could benefit from event metadata tracking

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) - EXCELLENT
The class has a single, focused responsibility: representing the pre-execution lifecycle event with request and extension data. No extraneous concerns.

**Score: 10/10**

### Open/Closed Principle (OCP) - EXCELLENT
The class is `final`, intentionally closed for extension to maintain a stable event contract. However, it's open for extension through event listeners without modifying the event class itself. This is the correct pattern for events.

**Score: 10/10**

### Liskov Substitution Principle (LSP) - EXCELLENT
The class properly extends `ExtensionEvent` without violating its contract. All inherited methods work correctly, and the constructor properly calls parent constructor.

**Score: 10/10**

### Interface Segregation Principle (ISP) - EXCELLENT
The class provides exactly what's needed: access to request and extension data. No unnecessary methods or properties.

**Score: 10/10**

### Dependency Inversion Principle (DIP) - EXCELLENT
Depends on data objects (`RequestObjectData`, `ExtensionData`) rather than concrete implementations. No coupling to infrastructure concerns.

**Score: 10/10**

---

## Code Quality Issues

### 🟡 Minor Issue #1: Missing Helper Methods for Common Patterns

**Issue:** Extensions will commonly check for specific extension options (cache keys, idempotency keys, rate limiting). No helper methods to streamline these common checks.

**Location:** Entire file (missing convenience methods)

**Impact:**
- Code duplication across extension listeners
- Verbose property access chains
- Reduced readability in event listeners

**Solution:**
```php
// Add to ExecutingFunction.php after the constructor (line 49):

/**
 * Check if a specific extension is enabled in this request.
 *
 * Convenience method to check if an extension option exists and
 * is not null. Useful for conditional extension logic.
 *
 * @param string $extensionName Extension name to check (e.g., 'cache', 'idempotency')
 * @return bool True if extension is enabled/present in request
 */
public function hasExtension(string $extensionName): bool
{
    // This assumes ExtensionData has a method like hasExtension() or similar
    // Adjust based on actual ExtensionData implementation
    return isset($this->extension->{$extensionName});
}

/**
 * Get extension options for a specific extension.
 *
 * Returns null if the extension is not present in the request.
 * Useful for accessing extension-specific configuration.
 *
 * @template T
 * @param string $extensionName Extension name (e.g., 'cache', 'idempotency')
 * @return null|T Extension options or null if not present
 */
public function getExtensionOptions(string $extensionName): mixed
{
    return $this->extension->{$extensionName} ?? null;
}

/**
 * Check if this event represents a cached function call.
 *
 * Convenience method for cache extensions to quickly determine
 * if caching is enabled for this request.
 *
 * @return bool True if cache extension is present
 */
public function hasCacheEnabled(): bool
{
    return $this->hasExtension('cache');
}

/**
 * Check if this request uses idempotency.
 *
 * Convenience method for idempotency extensions to determine
 * if idempotency handling is required.
 *
 * @return bool True if idempotency extension is present
 */
public function hasIdempotencyKey(): bool
{
    return $this->hasExtension('idempotency');
}
```

**Usage:**
```php
// Event listener for cache extension
class CacheExtensionListener
{
    public function handle(ExecutingFunction $event): void
    {
        // Instead of:
        if (isset($event->extension->cache)) {
            $cacheOptions = $event->extension->cache;
            // ...
        }

        // Use:
        if ($event->hasCacheEnabled()) {
            $cacheOptions = $event->getExtensionOptions('cache');
            // ...
        }
    }
}
```

**Note:** The exact implementation depends on the structure of `ExtensionData`. Adjust the methods based on its actual API.

### 🔵 Suggestion #1: Add Event Metadata Tracking

**Issue:** No way to track which extensions have inspected or modified the event. This could be useful for debugging and observability.

**Location:** Entire file (enhancement)

**Impact:** Limited observability into extension processing pipeline

**Solution:**
```php
// Add to ExecutingFunction.php:

/**
 * Track which extensions have processed this event.
 *
 * @var array<string>
 */
private array $processedByExtensions = [];

/**
 * Mark that an extension has processed this event.
 *
 * Used for tracking and debugging the extension pipeline.
 * Extensions should call this after processing the event.
 *
 * @param string $extensionName Name of the extension
 * @return void
 */
public function markProcessedBy(string $extensionName): void
{
    if (!in_array($extensionName, $this->processedByExtensions, true)) {
        $this->processedByExtensions[] = $extensionName;
    }
}

/**
 * Get list of extensions that have processed this event.
 *
 * Useful for debugging and observability to see which extensions
 * have inspected or modified the event.
 *
 * @return array<string> Extension names that processed this event
 */
public function getProcessedByExtensions(): array
{
    return $this->processedByExtensions;
}

/**
 * Check if a specific extension has already processed this event.
 *
 * @param string $extensionName Extension name to check
 * @return bool True if extension has processed this event
 */
public function wasProcessedBy(string $extensionName): bool
{
    return in_array($extensionName, $this->processedByExtensions, true);
}
```

**Usage:**
```php
class CacheExtensionListener
{
    public function handle(ExecutingFunction $event): void
    {
        // Avoid duplicate processing
        if ($event->wasProcessedBy('cache')) {
            return;
        }

        // Process cache logic
        $cachedResponse = $this->getCachedResponse($event->request);

        if ($cachedResponse !== null) {
            $event->setResponse($cachedResponse);
            $event->stopPropagation();
        }

        $event->markProcessedBy('cache');
    }
}
```

### 🔵 Suggestion #2: Add Timestamp Tracking

**Issue:** No visibility into when the event was created or dispatched, which could be useful for performance monitoring.

**Location:** Constructor (line 44-48)

**Impact:** Limited performance tracking and debugging capabilities

**Solution:**
```php
// Add to ExecutingFunction.php:

/**
 * Timestamp when the event was created.
 *
 * @var \DateTimeImmutable
 */
public readonly \DateTimeImmutable $createdAt;

/**
 * Create a new executing function event instance.
 *
 * @param RequestObjectData $request   The validated request object containing function name,
 *                                     arguments, protocol version, and metadata. This is the
 *                                     fully parsed request that will be executed unless an
 *                                     extension short-circuits execution.
 * @param ExtensionData     $extension Extension-specific data and options from the request.
 *                                     Contains configuration for extensions like caching,
 *                                     idempotency, replay, and custom extension parameters
 *                                     that control request processing behavior.
 */
public function __construct(
    RequestObjectData $request,
    public readonly ExtensionData $extension,
) {
    parent::__construct($request);
    $this->createdAt = new \DateTimeImmutable();
}

/**
 * Get the elapsed time since event creation in milliseconds.
 *
 * Useful for performance monitoring and detecting slow extensions.
 *
 * @return float Elapsed time in milliseconds
 */
public function getElapsedTime(): float
{
    $now = new \DateTimeImmutable();
    $interval = $now->getTimestamp() - $this->createdAt->getTimestamp();
    $microInterval = $now->format('u') - $this->createdAt->format('u');

    return ($interval * 1000) + ($microInterval / 1000);
}
```

**Usage:**
```php
// At the end of extension processing
public function handle(ExecutingFunction $event): void
{
    // ... extension logic ...

    $elapsed = $event->getElapsedTime();
    if ($elapsed > 100) { // More than 100ms
        logger()->warning('Slow extension processing detected', [
            'function' => $event->request->function,
            'elapsed_ms' => $elapsed,
        ]);
    }
}
```

---

## Security Vulnerabilities

### No Direct Security Issues Found

The event class itself doesn't introduce security vulnerabilities. However, there are security considerations for event listeners:

### 🟡 Security Consideration: Extension Data Validation

**Issue:** The event provides direct access to extension data without validation. Malicious or malformed extension data could cause issues in listeners.

**Location:** Line 46 (extension property)

**Impact:** Extension listeners must validate all extension data to avoid security issues

**Recommendation:**
```php
// In extension listeners, always validate extension data:

class CacheExtensionListener
{
    public function handle(ExecutingFunction $event): void
    {
        $cacheOptions = $event->getExtensionOptions('cache');

        // Validate cache options before use
        if (!is_array($cacheOptions)) {
            logger()->error('Invalid cache extension options', [
                'type' => get_debug_type($cacheOptions),
            ]);
            return;
        }

        // Validate required fields
        if (!isset($cacheOptions['key']) || !is_string($cacheOptions['key'])) {
            logger()->error('Invalid or missing cache key');
            return;
        }

        // Sanitize cache key to prevent cache poisoning
        $cacheKey = $this->sanitizeCacheKey($cacheOptions['key']);

        // ... rest of cache logic ...
    }

    private function sanitizeCacheKey(string $key): string
    {
        // Remove potentially dangerous characters
        return preg_replace('/[^a-zA-Z0-9:_-]/', '', $key);
    }
}
```

### 🟡 Security Consideration: Rate Limiting Extensions

**Issue:** Extensions that check rate limits should be prioritized to prevent resource exhaustion from expensive operations.

**Location:** Event listener ordering (not in this file)

**Recommendation:**
```php
// In EventServiceProvider, order listeners by priority:

protected $listen = [
    ExecutingFunction::class => [
        // High priority: Authentication and authorization
        AuthenticationExtensionListener::class . '@handle:100',

        // High priority: Rate limiting to prevent abuse
        RateLimitExtensionListener::class . '@handle:90',

        // Medium priority: Idempotency checks
        IdempotencyExtensionListener::class . '@handle:50',

        // Low priority: Caching (only if not rate limited)
        CacheExtensionListener::class . '@handle:10',
    ],
];
```

---

## Performance Concerns

### Excellent Performance Profile

**No performance issues.** The event class has minimal overhead:

1. **Object Creation:** Single object allocation with readonly properties
2. **Property Access:** Direct property access (O(1))
3. **Parent Constructor:** Minimal overhead from parent call
4. **Memory:** ~256-512 bytes per event instance (depends on data objects)

**Note:** The actual performance depends on:
- Number of event listeners registered
- Complexity of listener logic
- Whether short-circuiting occurs early

**Performance Best Practices for Listeners:**
```php
// Order listeners by likelihood of short-circuiting
class CacheExtensionListener
{
    public function handle(ExecutingFunction $event): void
    {
        // Fast path: cache hit (most common)
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            $event->setResponse($cached);
            $event->stopPropagation(); // Prevents other listeners from running
            return;
        }

        // Slow path: cache miss
        // Don't stop propagation - let function execute
    }
}
```

---

## Maintainability Assessment

### Excellent Maintainability - Score: 9.5/10

**Strengths:**
1. Crystal clear purpose and documentation
2. Proper use of `final` keyword prevents brittle inheritance
3. Readonly properties ensure immutability
4. Constructor parameter promotion reduces boilerplate
5. Follows Laravel event conventions perfectly

**Very Minor Weaknesses:**
1. Could benefit from usage examples in PHPDoc
2. No inline comments (though code is self-documenting)

**Suggested Documentation Enhancement:**
```php
/**
 * Event dispatched immediately before function execution begins.
 *
 * Fired after request validation and routing but before the target function
 * is invoked. This represents the last opportunity for extensions to intercept
 * and short-circuit execution by returning a cached or pre-computed response.
 *
 * Common use cases include cache lookups, idempotency checks, circuit breakers,
 * and request transformation. Extensions can call stopPropagation() and
 * setResponse() to bypass function execution entirely.
 *
 * @example Basic cache extension listener
 * ```php
 * class CacheExtensionListener
 * {
 *     public function handle(ExecutingFunction $event): void
 *     {
 *         if (!$event->hasCacheEnabled()) {
 *             return;
 *         }
 *
 *         $cacheKey = $this->buildCacheKey($event->request);
 *         $cached = $this->cache->get($cacheKey);
 *
 *         if ($cached !== null) {
 *             // Short-circuit execution with cached response
 *             $event->setResponse($cached);
 *             $event->stopPropagation();
 *         }
 *     }
 * }
 * ```
 *
 * @example Idempotency extension listener
 * ```php
 * class IdempotencyExtensionListener
 * {
 *     public function handle(ExecutingFunction $event): void
 *     {
 *         if (!$event->hasIdempotencyKey()) {
 *             return;
 *         }
 *
 *         $key = $event->getExtensionOptions('idempotency')['key'];
 *         $existingResponse = $this->idempotency->retrieve($key);
 *
 *         if ($existingResponse !== null) {
 *             // Return previously computed response
 *             $event->setResponse($existingResponse);
 *             $event->stopPropagation();
 *         }
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/protocol
 * @see ExtensionEvent For base event functionality
 */
```

---

## Testing Recommendations

The `ExecutingFunction` event requires tests covering event creation, property access, and integration with the base `ExtensionEvent` class.

```php
// tests/Unit/Events/ExecutingFunctionTest.php

namespace Tests\Unit\Events;

use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Events\ExecutingFunction;
use PHPUnit\Framework\TestCase;

final class ExecutingFunctionTest extends TestCase
{
    /** @test */
    public function it_can_be_constructed_with_request_and_extension_data(): void
    {
        $request = $this->createMock(RequestObjectData::class);
        $extension = $this->createMock(ExtensionData::class);

        $event = new ExecutingFunction($request, $extension);

        $this->assertSame($request, $event->request);
        $this->assertSame($extension, $event->extension);
    }

    /** @test */
    public function it_inherits_event_behavior_from_extension_event(): void
    {
        $event = new ExecutingFunction(
            $this->createMock(RequestObjectData::class),
            $this->createMock(ExtensionData::class)
        );

        // Should have base ExtensionEvent behavior
        $this->assertFalse($event->isPropagationStopped());
        $this->assertNull($event->getResponse());
    }

    /** @test */
    public function it_can_stop_propagation(): void
    {
        $event = new ExecutingFunction(
            $this->createMock(RequestObjectData::class),
            $this->createMock(ExtensionData::class)
        );

        $event->stopPropagation();

        $this->assertTrue($event->isPropagationStopped());
    }

    /** @test */
    public function it_can_set_short_circuit_response(): void
    {
        $event = new ExecutingFunction(
            $this->createMock(RequestObjectData::class),
            $this->createMock(ExtensionData::class)
        );

        $response = $this->createMock(ResponseData::class);
        $event->setResponse($response);

        $this->assertSame($response, $event->getResponse());
    }

    /** @test */
    public function extension_property_is_readonly(): void
    {
        $event = new ExecutingFunction(
            $this->createMock(RequestObjectData::class),
            $this->createMock(ExtensionData::class)
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        // @phpstan-ignore-next-line - Intentionally testing readonly violation
        $event->extension = $this->createMock(ExtensionData::class);
    }

    /** @test */
    public function it_is_final_and_cannot_be_extended(): void
    {
        $reflection = new \ReflectionClass(ExecutingFunction::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'ExecutingFunction must be final to maintain stable event contract'
        );
    }

    /** @test */
    public function it_can_be_dispatched_with_laravel_event_system(): void
    {
        $event = new ExecutingFunction(
            $this->createMock(RequestObjectData::class),
            $this->createMock(ExtensionData::class)
        );

        // Should have Dispatchable trait
        $this->assertTrue(
            method_exists($event, 'dispatch'),
            'Event should use Dispatchable trait'
        );
    }
}
```

---

## Integration Testing Recommendations

```php
// tests/Feature/Events/ExecutingFunctionIntegrationTest.php

namespace Tests\Feature\Events;

use Cline\Forrst\Events\ExecutingFunction;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ExecutingFunctionIntegrationTest extends TestCase
{
    /** @test */
    public function cache_extension_can_short_circuit_execution(): void
    {
        Event::listen(ExecutingFunction::class, function (ExecutingFunction $event) {
            // Simulate cache hit
            $cachedResponse = new ResponseData(/* ... */);
            $event->setResponse($cachedResponse);
            $event->stopPropagation();
        });

        // Dispatch event
        $event = new ExecutingFunction($request, $extension);
        event($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertNotNull($event->getResponse());
    }

    /** @test */
    public function multiple_listeners_are_called_until_propagation_stopped(): void
    {
        $callOrder = [];

        Event::listen(ExecutingFunction::class, function () use (&$callOrder) {
            $callOrder[] = 'listener1';
        });

        Event::listen(ExecutingFunction::class, function (ExecutingFunction $event) use (&$callOrder) {
            $callOrder[] = 'listener2';
            $event->stopPropagation(); // Stop here
        });

        Event::listen(ExecutingFunction::class, function () use (&$callOrder) {
            $callOrder[] = 'listener3'; // Should not be called
        });

        $event = new ExecutingFunction($request, $extension);
        event($event);

        $this->assertSame(['listener1', 'listener2'], $callOrder);
    }
}
```

---

## Additional Recommendations

### 1. Create Extension Listener Base Class

```php
// src/Events/Listeners/ExtensionListener.php

namespace Cline\Forrst\Events\Listeners;

use Cline\Forrst\Events\ExecutingFunction;

/**
 * Abstract base class for extension listeners.
 *
 * Provides common functionality for extension event listeners
 * including extension checking and logging.
 */
abstract class ExtensionListener
{
    /**
     * Get the extension name this listener handles.
     *
     * @return string Extension name (e.g., 'cache', 'idempotency')
     */
    abstract protected function getExtensionName(): string;

    /**
     * Check if this listener should process the event.
     *
     * @param ExecutingFunction $event
     * @return bool True if extension is present in request
     */
    protected function shouldHandle(ExecutingFunction $event): bool
    {
        return $event->hasExtension($this->getExtensionName());
    }

    /**
     * Log extension processing.
     *
     * @param ExecutingFunction $event
     * @param string $message
     * @param array<string, mixed> $context
     */
    protected function log(ExecutingFunction $event, string $message, array $context = []): void
    {
        logger()->debug($message, array_merge([
            'extension' => $this->getExtensionName(),
            'function' => $event->request->function ?? 'unknown',
        ], $context));
    }
}
```

### 2. Document Extension Priority Guidelines

```markdown
<!-- docs/extensions/listener-priority.md -->

# Extension Listener Priority Guidelines

Listeners for `ExecutingFunction` should be ordered by priority to optimize performance and security:

## Priority Levels

### Critical Priority (90-100)
- Authentication verification
- Authorization checks
- Must execute before any other extensions

### High Priority (70-89)
- Rate limiting
- Request validation
- Circuit breakers
- Should execute early to prevent resource waste

### Medium Priority (40-69)
- Idempotency checks
- Request transformation
- Logging and metrics

### Low Priority (10-39)
- Caching (cache hits short-circuit)
- Default handlers
- Fallback logic

## Example Configuration

```php
// config/forrst.php
return [
    'extension_priorities' => [
        'authentication' => 100,
        'authorization' => 95,
        'rate_limit' => 85,
        'idempotency' => 50,
        'cache' => 20,
    ],
];
```
```

---

## Conclusion

The `ExecutingFunction` event is excellently implemented with strong adherence to SOLID principles, Laravel conventions, and best practices. It's production-ready with only minor enhancement opportunities for improved developer experience.

**Final Score: 9.5/10**

**Strengths:**
- Perfect SOLID principles adherence
- Clear, comprehensive documentation
- Proper use of `final` and `readonly`
- Clean integration with ExtensionEvent base
- Minimal performance overhead

**Suggested Improvements (All Minor):**
1. Add convenience methods for common extension checks
2. Add metadata tracking for debugging
3. Add timestamp tracking for performance monitoring
4. Enhance documentation with usage examples

**Recommended Next Steps:**
1. Add helper methods for common patterns (Minor Issue #1) - **Priority: LOW**
2. Enhance documentation with examples - **Priority: LOW**
3. Create comprehensive test suite - **Priority: MEDIUM**
4. Document extension listener priority guidelines - **Priority: MEDIUM**
5. Create extension listener base class - **Priority: LOW**

**Overall Assessment:** Excellent implementation. The suggested improvements are all optional enhancements that would improve developer experience but are not required for production use.

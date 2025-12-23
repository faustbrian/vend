# Code Review: ExtensionEvent.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Events/ExtensionEvent.php`

**Purpose:** Abstract base class for Forrst extension lifecycle events, providing infrastructure for event propagation control, short-circuit responses, and request data access.

---

## Executive Summary

The `ExtensionEvent` abstract class is a well-designed foundation for the Forrst event system. It properly implements event propagation control and short-circuit response handling with clean abstractions. However, there's a critical design flaw with mutable response handling that could lead to subtle bugs, and the class would benefit from stricter contract enforcement.

**Strengths:**
- Clear separation of concerns with abstract base pattern
- Proper event propagation stop mechanism
- Good use of Laravel's Dispatchable trait
- Comprehensive documentation
- Clean, readable implementation

**Areas for Improvement:**
- **Critical:** Mutable protected properties allow uncontrolled state changes
- Missing template method pattern for subclass hooks
- No validation for response state consistency
- Lacks event lifecycle hooks
- Missing immutability guarantees for safety-critical properties

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) - EXCELLENT
The class has a focused responsibility: providing base event infrastructure for extension lifecycle events. Event propagation, response handling, and request data access are cohesive concerns.

**Score: 10/10**

### Open/Closed Principle (OCP) - EXCELLENT
The abstract class is open for extension (concrete events extend it) while closed for modification. Subclasses can add their own properties and behavior without changing the base.

**Score: 10/10**

### Liskov Substitution Principle (LSP) - EXCELLENT
All subclasses can be used interchangeably where `ExtensionEvent` is expected. The contract is clear and consistent.

**Score: 10/10**

### Interface Segregation Principle (ISP) - GOOD
The interface is minimal and focused. However, some methods (setResponse, getResponse) might not be needed by all subclasses, suggesting a potential interface segregation opportunity.

**Score: 8/10**

### Dependency Inversion Principle (DIP) - EXCELLENT
Depends on data abstractions (`RequestObjectData`, `ResponseData`) rather than concrete implementations.

**Score: 10/10**

---

## Code Quality Issues

### 🔴 Critical Issue #1: Mutable Protected Properties Create Race Conditions

**Issue:** The `$propagationStopped` and `$shortCircuitResponse` properties are protected (not private), allowing subclasses to directly mutate them. This breaks encapsulation and creates potential for inconsistent state where propagation is stopped without a response set, or vice versa.

**Location:** Lines 48, 58

**Impact:**
- **Data Integrity:** Subclasses could set inconsistent state
- **Race Conditions:** Direct property modification bypasses validation
- **Debugging Difficulty:** State changes outside public API are hard to track
- **Contract Violation:** Public methods may return values inconsistent with direct property access

**Solution:**
```php
// Replace lines 48 and 58 in ExtensionEvent.php:

/**
 * Indicates whether event propagation has been stopped.
 *
 * When true, the event dispatcher will not invoke any remaining listeners
 * for this event. Used to short-circuit processing when an extension has
 * handled the event and wants to prevent further processing.
 */
private bool $propagationStopped = false;  // Changed from protected to private

/**
 * Response to return immediately, bypassing normal execution.
 *
 * When set in combination with stopped propagation, this response will be
 * returned to the client instead of executing the requested function. Used
 * by caching extensions to return cached responses or by idempotency
 * extensions to return previously computed results.
 */
private ?ResponseData $shortCircuitResponse = null;  // Changed from protected to private
```

**Why This Matters:**
```php
// Without this fix, subclasses could do:
class BadEvent extends ExtensionEvent
{
    public function breakThings(): void
    {
        // Direct property access breaks the public API contract
        $this->propagationStopped = true;
        // But forget to set response - now we have inconsistent state!
    }
}

// With private properties, only public methods can modify state:
class GoodEvent extends ExtensionEvent
{
    public function properUsage(): void
    {
        // Must use public API which enforces consistency
        $this->stopPropagation();
        $this->setResponse($response);
    }
}
```

### 🟠 Major Issue #1: No Validation for Response State Consistency

**Issue:** The class allows `stopPropagation()` to be called without `setResponse()`, and vice versa. This creates ambiguous states where it's unclear what should happen.

**Location:** Lines 80-83 (stopPropagation), Lines 110-113 (setResponse)

**Impact:**
- Ambiguous behavior: What happens if propagation stopped but no response set?
- Difficult debugging: Unclear which component is responsible for the state
- Potential null pointer exceptions when dispatcher expects a response

**Solution:**
```php
// Add validation and state checking methods to ExtensionEvent.php:

/**
 * Check if a short-circuit response is set.
 *
 * Returns true if setResponse() has been called with a non-null response.
 * Useful for determining if the event has been fully handled.
 *
 * @return bool True if response is set, false otherwise
 */
public function hasResponse(): bool
{
    return $this->shortCircuitResponse !== null;
}

/**
 * Check if the event is in a valid short-circuit state.
 *
 * A valid short-circuit requires both propagation stopped AND a response set.
 * This ensures the dispatcher knows exactly what to do with a stopped event.
 *
 * @return bool True if event is properly short-circuited
 */
public function isShortCircuited(): bool
{
    return $this->propagationStopped && $this->shortCircuitResponse !== null;
}

/**
 * Stop propagation and set response atomically.
 *
 * Convenience method that ensures propagation is stopped and response is set
 * together, preventing inconsistent state. This is the recommended way to
 * short-circuit execution.
 *
 * @param ResponseData $response The response to return to the client
 * @return void
 */
public function shortCircuit(ResponseData $response): void
{
    $this->shortCircuitResponse = $response;
    $this->propagationStopped = true;
}

// Update setResponse() to log warning if propagation not stopped:
/**
 * Set a response to return immediately, bypassing function execution.
 *
 * Provides a response that will be returned to the client instead of
 * executing the requested function. Should be used with stopPropagation()
 * to prevent subsequent listeners from modifying the response. Consider
 * using shortCircuit() instead to atomically set both.
 *
 * @param ResponseData $response The response to return to the client, containing
 *                               result data, metadata, and any error information
 */
public function setResponse(ResponseData $response): void
{
    $this->shortCircuitResponse = $response;

    // Log warning if propagation not stopped
    if (!$this->propagationStopped) {
        logger()->warning('Response set on event without stopping propagation', [
            'event_class' => static::class,
            'function' => $this->request->function ?? 'unknown',
        ]);
    }
}
```

**Usage:**
```php
// Instead of:
$event->setResponse($response);
$event->stopPropagation();

// Use atomic method:
$event->shortCircuit($response);

// Or check state:
if ($event->isShortCircuited()) {
    return $event->getResponse();
}
```

### 🟡 Minor Issue #1: Missing Template Method Hooks

**Issue:** Subclasses cannot hook into event lifecycle (before/after propagation stops, before/after response set) without overriding public methods.

**Location:** Entire class (missing functionality)

**Impact:**
- Limited extensibility for subclasses wanting to add behavior
- Forces method overriding which can break the public API contract
- No centralized place to add logging, metrics, or validation

**Solution:**
```php
// Add to ExtensionEvent.php:

/**
 * Hook called before propagation is stopped.
 *
 * Subclasses can override to add custom behavior when propagation
 * is about to be stopped. Return false to prevent stopping propagation.
 *
 * @return bool True to allow stopping, false to prevent
 */
protected function beforeStopPropagation(): bool
{
    return true;
}

/**
 * Hook called after propagation has been stopped.
 *
 * Subclasses can override to add custom behavior after propagation
 * is stopped, such as logging or metrics collection.
 *
 * @return void
 */
protected function afterStopPropagation(): void
{
    // Default: no-op
}

/**
 * Hook called before response is set.
 *
 * Subclasses can override to validate or transform the response
 * before it's stored. Return the response to set (possibly modified).
 *
 * @param ResponseData $response The response being set
 * @return ResponseData The response to actually store (possibly modified)
 */
protected function beforeSetResponse(ResponseData $response): ResponseData
{
    return $response;
}

/**
 * Hook called after response has been set.
 *
 * Subclasses can override to add custom behavior after response
 * is set, such as logging or metrics collection.
 *
 * @param ResponseData $response The response that was set
 * @return void
 */
protected function afterSetResponse(ResponseData $response): void
{
    // Default: no-op
}

// Update stopPropagation() to use hooks:
public function stopPropagation(): void
{
    if (!$this->beforeStopPropagation()) {
        return;
    }

    $this->propagationStopped = true;
    $this->afterStopPropagation();
}

// Update setResponse() to use hooks:
public function setResponse(ResponseData $response): void
{
    $response = $this->beforeSetResponse($response);
    $this->shortCircuitResponse = $response;
    $this->afterSetResponse($response);

    if (!$this->propagationStopped) {
        logger()->warning('Response set without stopping propagation', [
            'event_class' => static::class,
        ]);
    }
}
```

**Usage:**
```php
class LoggingEvent extends ExtensionEvent
{
    protected function afterStopPropagation(): void
    {
        logger()->info('Event propagation stopped', [
            'event' => static::class,
            'function' => $this->request->function,
        ]);
    }

    protected function beforeSetResponse(ResponseData $response): ResponseData
    {
        // Validate response before setting
        if (!isset($response->result)) {
            throw new \InvalidArgumentException('Response must have result');
        }

        return $response;
    }
}
```

### 🟡 Minor Issue #2: No Event Metadata for Debugging

**Issue:** No way to track event lifecycle metadata like creation time, listener count, or processing duration.

**Location:** Entire class (missing functionality)

**Impact:** Limited debugging and observability capabilities

**Solution:**
```php
// Add to ExtensionEvent.php:

/**
 * Metadata about event lifecycle and processing.
 *
 * @var array{created_at: \DateTimeImmutable, listeners_processed: int}
 */
private array $metadata;

/**
 * Create a new extension event instance.
 *
 * @param RequestObjectData $request The validated request object being processed.
 *                                   Provides extensions with access to function name,
 *                                   arguments, protocol version, extension options,
 *                                   and request metadata for decision-making.
 */
public function __construct(
    public readonly RequestObjectData $request,
) {
    $this->metadata = [
        'created_at' => new \DateTimeImmutable(),
        'listeners_processed' => 0,
        'stopped_at' => null,
        'response_set_at' => null,
    ];
}

/**
 * Increment the count of listeners that have processed this event.
 *
 * Should be called by the event dispatcher after each listener executes.
 *
 * @return void
 */
public function incrementListenerCount(): void
{
    $this->metadata['listeners_processed']++;
}

/**
 * Get event processing metadata.
 *
 * Returns diagnostic information about event lifecycle useful for
 * debugging, monitoring, and performance analysis.
 *
 * @return array{created_at: \DateTimeImmutable, listeners_processed: int, stopped_at: ?\DateTimeImmutable, response_set_at: ?\DateTimeImmutable}
 */
public function getMetadata(): array
{
    return $this->metadata;
}

/**
 * Get the elapsed time since event creation in milliseconds.
 *
 * @return float Elapsed milliseconds
 */
public function getElapsedMs(): float
{
    $now = new \DateTimeImmutable();
    return ($now->getTimestamp() - $this->metadata['created_at']->getTimestamp()) * 1000
        + ($now->format('u') - $this->metadata['created_at']->format('u')) / 1000;
}

// Update stopPropagation():
public function stopPropagation(): void
{
    if (!$this->beforeStopPropagation()) {
        return;
    }

    $this->propagationStopped = true;
    $this->metadata['stopped_at'] = new \DateTimeImmutable();
    $this->afterStopPropagation();
}

// Update setResponse():
public function setResponse(ResponseData $response): void
{
    $response = $this->beforeSetResponse($response);
    $this->shortCircuitResponse = $response;
    $this->metadata['response_set_at'] = new \DateTimeImmutable();
    $this->afterSetResponse($response);

    if (!$this->propagationStopped) {
        logger()->warning('Response set without stopping propagation', [
            'event_class' => static::class,
            'elapsed_ms' => $this->getElapsedMs(),
        ]);
    }
}
```

---

## Security Vulnerabilities

### No Direct Security Issues

The base event class doesn't introduce direct security vulnerabilities. However, there are security considerations for usage:

### 🟡 Security Consideration: Prevent Response Tampering

**Issue:** Once a response is set, it can be overwritten by subsequent code. This could allow malicious extensions to tamper with security-sensitive responses.

**Location:** Line 110-113 (setResponse method)

**Impact:** Response tampering, potential security bypass

**Recommendation:**
```php
// Add response immutability after first set:

/**
 * Whether the response has been locked (made immutable).
 */
private bool $responseLocked = false;

/**
 * Set a response to return immediately, bypassing function execution.
 *
 * Once set, the response cannot be changed unless explicitly unlocked.
 * This prevents response tampering by subsequent extensions.
 *
 * @param ResponseData $response The response to return to the client
 * @throws \RuntimeException If response is already set and locked
 * @return void
 */
public function setResponse(ResponseData $response): void
{
    if ($this->responseLocked && $this->shortCircuitResponse !== null) {
        throw new \RuntimeException(
            'Cannot modify response after it has been locked'
        );
    }

    $response = $this->beforeSetResponse($response);
    $this->shortCircuitResponse = $response;
    $this->responseLocked = true; // Auto-lock after setting
    $this->metadata['response_set_at'] = new \DateTimeImmutable();
    $this->afterSetResponse($response);
}

/**
 * Unlock the response to allow modification.
 *
 * SECURITY WARNING: Only use this if you understand the implications.
 * Unlocking responses can allow tampering by malicious extensions.
 *
 * @return void
 */
public function unlockResponse(): void
{
    $this->responseLocked = false;
}
```

---

## Performance Concerns

### Excellent Performance Profile

**No significant performance issues.** The base class has minimal overhead:

1. **Object Creation:** Single allocation with minimal properties
2. **Property Access:** Direct O(1) access
3. **Method Calls:** Minimal computational overhead
4. **Memory:** ~512 bytes per instance (depends on request/response data)

**Performance Notes:**
- The Dispatchable trait adds minimal overhead
- Propagation stopping is O(1) operation
- Event dispatching performance depends on listener count and complexity

---

## Maintainability Assessment

### Good Maintainability - Score: 8.5/10

**Strengths:**
1. Clear, comprehensive documentation
2. Logical method organization
3. Consistent naming conventions
4. Good use of nullable types
5. Proper abstraction for extension events

**Weaknesses:**
1. Protected properties break encapsulation (Critical Issue #1)
2. No validation for state consistency (Major Issue #1)
3. Limited extensibility hooks (Minor Issue #1)
4. Missing debugging metadata (Minor Issue #2)

**Testing Recommendations:**

```php
// tests/Unit/Events/ExtensionEventTest.php

namespace Tests\Unit\Events;

use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Events\ExtensionEvent;
use PHPUnit\Framework\TestCase;

final class ExtensionEventTest extends TestCase
{
    private ExtensionEvent $event;

    protected function setUp(): void
    {
        $request = $this->createMock(RequestObjectData::class);
        $this->event = new class($request) extends ExtensionEvent {};
    }

    /** @test */
    public function it_starts_with_propagation_not_stopped(): void
    {
        $this->assertFalse($this->event->isPropagationStopped());
    }

    /** @test */
    public function it_can_stop_propagation(): void
    {
        $this->event->stopPropagation();

        $this->assertTrue($this->event->isPropagationStopped());
    }

    /** @test */
    public function it_starts_without_response(): void
    {
        $this->assertNull($this->event->getResponse());
        $this->assertFalse($this->event->hasResponse());
    }

    /** @test */
    public function it_can_set_response(): void
    {
        $response = $this->createMock(ResponseData::class);

        $this->event->setResponse($response);

        $this->assertSame($response, $this->event->getResponse());
        $this->assertTrue($this->event->hasResponse());
    }

    /** @test */
    public function short_circuit_sets_both_response_and_stops_propagation(): void
    {
        $response = $this->createMock(ResponseData::class);

        $this->event->shortCircuit($response);

        $this->assertTrue($this->event->isPropagationStopped());
        $this->assertSame($response, $this->event->getResponse());
        $this->assertTrue($this->event->isShortCircuited());
    }

    /** @test */
    public function is_short_circuited_requires_both_stopped_and_response(): void
    {
        // Only stopped
        $this->event->stopPropagation();
        $this->assertFalse($this->event->isShortCircuited());

        // Only response
        $event2 = new class($this->createMock(RequestObjectData::class)) extends ExtensionEvent {};
        $event2->setResponse($this->createMock(ResponseData::class));
        $this->assertFalse($event2->isShortCircuited());

        // Both
        $this->event->setResponse($this->createMock(ResponseData::class));
        $this->assertTrue($this->event->isShortCircuited());
    }

    /** @test */
    public function request_property_is_readonly(): void
    {
        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $this->event->request = $this->createMock(RequestObjectData::class);
    }

    /** @test */
    public function event_has_dispatchable_trait(): void
    {
        $this->assertTrue(
            method_exists($this->event, 'dispatch'),
            'ExtensionEvent should use Dispatchable trait'
        );
    }

    /** @test */
    public function subclasses_can_use_lifecycle_hooks(): void
    {
        $hooksCalled = [];

        $event = new class($this->createMock(RequestObjectData::class), $hooksCalled) extends ExtensionEvent {
            public function __construct(
                RequestObjectData $request,
                private array &$hooksCalled
            ) {
                parent::__construct($request);
            }

            protected function beforeStopPropagation(): bool
            {
                $this->hooksCalled[] = 'beforeStop';
                return true;
            }

            protected function afterStopPropagation(): void
            {
                $this->hooksCalled[] = 'afterStop';
            }

            protected function beforeSetResponse(ResponseData $response): ResponseData
            {
                $this->hooksCalled[] = 'beforeSetResponse';
                return $response;
            }

            protected function afterSetResponse(ResponseData $response): void
            {
                $this->hooksCalled[] = 'afterSetResponse';
            }
        };

        $event->stopPropagation();
        $event->setResponse($this->createMock(ResponseData::class));

        $this->assertSame(
            ['beforeStop', 'afterStop', 'beforeSetResponse', 'afterSetResponse'],
            $hooksCalled
        );
    }

    /** @test */
    public function it_tracks_event_metadata(): void
    {
        $metadata = $this->event->getMetadata();

        $this->assertArrayHasKey('created_at', $metadata);
        $this->assertInstanceOf(\DateTimeImmutable::class, $metadata['created_at']);
        $this->assertSame(0, $metadata['listeners_processed']);
    }

    /** @test */
    public function it_tracks_elapsed_time(): void
    {
        usleep(10000); // 10ms
        $elapsed = $this->event->getElapsedMs();

        $this->assertGreaterThanOrEqual(10, $elapsed);
        $this->assertLessThan(100, $elapsed); // Sanity check
    }

    /** @test */
    public function response_is_locked_after_setting_and_cannot_be_changed(): void
    {
        $response1 = $this->createMock(ResponseData::class);
        $response2 = $this->createMock(ResponseData::class);

        $this->event->setResponse($response1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locked');

        $this->event->setResponse($response2);
    }

    /** @test */
    public function response_can_be_changed_after_unlocking(): void
    {
        $response1 = $this->createMock(ResponseData::class);
        $response2 = $this->createMock(ResponseData::class);

        $this->event->setResponse($response1);
        $this->event->unlockResponse();
        $this->event->setResponse($response2);

        $this->assertSame($response2, $this->event->getResponse());
    }
}
```

---

## Additional Recommendations

### 1. Add Event Serialization Support

```php
// Add to ExtensionEvent.php:

/**
 * Serialize event to array for logging or debugging.
 *
 * @return array{
 *     class: string,
 *     propagation_stopped: bool,
 *     has_response: bool,
 *     metadata: array
 * }
 */
public function toArray(): array
{
    return [
        'class' => static::class,
        'propagation_stopped' => $this->propagationStopped,
        'has_response' => $this->shortCircuitResponse !== null,
        'metadata' => $this->metadata,
    ];
}
```

### 2. Document Event Lifecycle

```php
/**
 * Abstract base class for Forrst extension lifecycle events.
 *
 * Event Lifecycle:
 * 1. Event created and dispatched
 * 2. Listeners process event in priority order
 * 3. Listener may call shortCircuit() or setResponse() + stopPropagation()
 * 4. If propagation stopped, remaining listeners are skipped
 * 5. Dispatcher checks isShortCircuited() to determine next action
 * 6. If short-circuited, response is returned; otherwise execution continues
 *
 * ... rest of documentation ...
 */
```

---

## Conclusion

The `ExtensionEvent` base class provides a solid foundation for the Forrst event system with good abstractions and clear responsibilities. However, the protected property visibility creates a critical encapsulation issue that should be addressed immediately.

**Final Score: 8.5/10**

**Critical Improvements:**
1. **Change protected properties to private** (Critical Issue #1) - **Priority: CRITICAL**
2. **Add state validation and atomic short-circuit method** (Major Issue #1) - **Priority: HIGH**

**Recommended Improvements:**
3. Add template method hooks for lifecycle events (Minor Issue #1) - **Priority: MEDIUM**
4. Add event metadata tracking (Minor Issue #2) - **Priority: LOW**
5. Add response locking for security (Security Consideration) - **Priority: MEDIUM**
6. Create comprehensive test suite - **Priority: HIGH**

**Implementation Priority:** The protected-to-private change is CRITICAL and should be done immediately to prevent subclasses from violating encapsulation and creating inconsistent state.

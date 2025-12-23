# Code Review: ExtensionInterface.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/Contracts/ExtensionInterface.php`
- **Purpose**: Contract for implementing Forrst protocol extensions that add optional capabilities via event lifecycle hooks
- **Type**: Interface / Contract

## SOLID Principles Adherence

### ✅ Single Responsibility Principle (SRP)
**Status**: EXCELLENT

The interface defines a focused contract for extension behavior: URN identification, execution policies (global/fatal flags), event subscriptions, and capability reporting. All methods relate to the single responsibility of defining extension behavior.

### ✅ Open/Closed Principle (OCP)
**Status**: EXCELLENT

The interface is open for extension (new implementations) while closed for modification. The event subscription system allows extensions to add behavior without modifying core code.

### ✅ Interface Segregation Principle (ISP)
**Status**: GOOD with Minor Concern

**Issue**: The interface combines several distinct concerns that some implementations might not need:
- Identity (`getUrn()`)
- Configuration (`isGlobal()`, `isErrorFatal()`)
- Event handling (`getSubscribedEvents()`)
- Capability reporting (`toCapabilities()`)

**Analysis**: While this could theoretically be split, the cohesion is appropriate for extensions. All methods are essential for extension lifecycle management.

**Rating**: Acceptable - the methods are cohesive enough to justify a single interface.

### ✅ Dependency Inversion Principle (DIP)
**Status**: EXCELLENT

The interface depends on the abstraction `ExtensionEvent` rather than concrete event types, promoting loose coupling.

## Code Quality Analysis

### Documentation Quality
**Rating**: 🟢 EXCELLENT

The documentation is exceptionally comprehensive:
- Clear description of extension purpose and lifecycle
- Detailed lifecycle event ordering with descriptions
- Priority range guidelines (0-9, 10-19, 20-29, etc.)
- Multiple `@see` references to related documentation
- Clear explanations of global vs. opt-in extensions
- Fatal vs. non-fatal error handling explained

**Strength**: The priority range documentation (lines 75-82) is particularly valuable for developers implementing extensions.

### Method Documentation Analysis

#### ✅ getUrn() - EXCELLENT
Clear documentation with example URN format.

#### ✅ isGlobal() - EXCELLENT
Clearly explains the difference between global and opt-in extensions with examples.

#### ✅ isErrorFatal() - EXCELLENT
Provides clear guidance on when to use fatal vs. non-fatal, with specific examples (idempotency vs. deprecation warnings).

#### 🟡 getSubscribedEvents() - GOOD with Enhancement Opportunity

**Location**: Lines 70-86

**Current Documentation**: Good, but the return type array structure could be clearer.

**Enhancement**:
```php
/**
 * Get event subscriptions with priorities.
 *
 * Returns a map of event class names to subscription config. Each config
 * must include 'priority' (lower = earlier) and 'method' (handler name).
 *
 * Priority ranges:
 * - 0-9: Infrastructure (tracing)
 * - 10-19: Fast-fail (deadline)
 * - 20-29: Short-circuit (caching, idempotency)
 * - 30-39: Validation (batch, dry-run)
 * - 40-49: Execution modifiers (priority, async)
 * - 100: Default
 * - 200+: Post-processing (deprecation, quota)
 *
 * @example
 * ```php
 * public function getSubscribedEvents(): array
 * {
 *     return [
 *         RequestValidated::class => [
 *             'priority' => 20,
 *             'method' => 'handleRequestValidated',
 *         ],
 *         ExecutingFunction::class => [
 *             'priority' => 25,
 *             'method' => 'beforeExecution',
 *         ],
 *     ];
 * }
 * ```
 *
 * @return array<class-string<ExtensionEvent>, array{priority: int, method: string}>
 */
```

#### ✅ toCapabilities() - GOOD
Clear return type documentation with exact structure.

## Type Safety Analysis

### 🟡 Medium: Array Return Types Lack Specificity

**Issue**: Several methods return arrays with complex structures but rely on documentation rather than type enforcement.

**Location**: Lines 84, 91

**Impact**: MEDIUM - Runtime errors if implementations return incorrect array structures

**Current Code**:
```php
public function getSubscribedEvents(): array;
public function toCapabilities(): array;
```

**Solution**: Use value objects or specific array shapes:

**Option 1: Value Objects (Recommended)**:
```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Extensions;

final readonly class EventSubscription
{
    public function __construct(
        public int $priority,
        public string $method,
    ) {}
}

final readonly class ExtensionCapability
{
    public function __construct(
        public string $urn,
        public ?string $documentation = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'urn' => $this->urn,
            'documentation' => $this->documentation,
        ], fn($value) => $value !== null);
    }
}
```

Update interface:
```php
/**
 * @return array<class-string<ExtensionEvent>, EventSubscription>
 */
public function getSubscribedEvents(): array;

public function toCapabilities(): ExtensionCapability;
```

**Option 2: PHP 8.2+ Array Shapes (Static Analysis Only)**:
```php
/**
 * @return array{urn: string, documentation?: string}
 */
public function toCapabilities(): array;
```

**Recommendation**: Use value objects for better runtime safety and IDE support.

### Missing Input Validation Contracts

**Issue**: The interface doesn't specify validation requirements for return values.

**Enhancement**: Consider adding validation methods or constraints:
```php
/**
 * Validate extension configuration.
 *
 * Called during extension registration to ensure proper setup.
 *
 * @throws \InvalidArgumentException If configuration is invalid
 */
public function validate(): void;
```

Implementation example:
```php
public function validate(): void
{
    if (!Urn::isValid($this->getUrn())) {
        throw new \InvalidArgumentException("Invalid URN: {$this->getUrn()}");
    }

    foreach ($this->getSubscribedEvents() as $event => $config) {
        if (!isset($config['priority'], $config['method'])) {
            throw new \InvalidArgumentException(
                "Event subscription for {$event} missing 'priority' or 'method'"
            );
        }

        if (!method_exists($this, $config['method'])) {
            throw new \InvalidArgumentException(
                "Handler method {$config['method']} does not exist"
            );
        }
    }
}
```

## Security Analysis

### 🟠 Major: Method Name Injection Risk

**Issue**: The `getSubscribedEvents()` method returns method names as strings, which are later called dynamically. If an extension implementation doesn't properly validate these, it could lead to unintended method invocation.

**Location**: Line 84 (return type), usage in event dispatcher

**Impact**: MEDIUM to HIGH - Potential for calling unintended methods if validation is missing in the event dispatcher

**Current Risk**:
```php
// In an extension implementation
public function getSubscribedEvents(): array
{
    return [
        RequestValidated::class => [
            'priority' => 10,
            'method' => 'handleRequest', // What if this method doesn't exist?
        ],
    ];
}
```

**Solution 1: Runtime Validation in Event Dispatcher**:
```php
// In the event dispatcher/subscriber
foreach ($extension->getSubscribedEvents() as $eventClass => $config) {
    if (!method_exists($extension, $config['method'])) {
        throw new \RuntimeException(sprintf(
            'Extension %s references non-existent method %s for event %s',
            get_class($extension),
            $config['method'],
            $eventClass
        ));
    }

    if (!is_callable([$extension, $config['method']])) {
        throw new \RuntimeException(sprintf(
            'Method %s::%s is not callable',
            get_class($extension),
            $config['method']
        ));
    }
}
```

**Solution 2: Use Closure-Based Subscriptions (Breaking Change)**:
```php
/**
 * @return array<class-string<ExtensionEvent>, array{priority: int, handler: \Closure}>
 */
public function getSubscribedEvents(): array
{
    return [
        RequestValidated::class => [
            'priority' => 10,
            'handler' => $this->handleRequest(...),
        ],
    ];
}
```

**Recommendation**: Implement Solution 1 immediately in the event dispatcher. Consider Solution 2 for a future major version.

### 🔵 Low: URN Validation Missing

**Issue**: No requirement to validate URN format in `getUrn()`.

**Solution**: Document that implementations should use `Urn::extension()` helper:
```php
/**
 * Get the extension URN.
 *
 * MUST use Urn::extension() to ensure proper URN format.
 *
 * @example
 * ```php
 * public function getUrn(): string
 * {
 *     return Urn::extension('caching');
 * }
 * ```
 *
 * @return string The URN identifying this extension (e.g., "urn:forrst:ext:caching")
 */
public function getUrn(): string;
```

## Performance Considerations

### Event Subscription Overhead
**Rating**: 🟢 GOOD

The event subscription pattern is efficient. However, consider documenting performance implications:

**Documentation Addition**:
```php
/**
 * Get event subscriptions with priorities.
 *
 * PERFORMANCE: This method may be called multiple times during extension
 * registration. Implementations should return a static array rather than
 * building it dynamically on each call.
 *
 * @example
 * ```php
 * private const SUBSCRIPTIONS = [
 *     RequestValidated::class => ['priority' => 20, 'method' => 'validate'],
 * ];
 *
 * public function getSubscribedEvents(): array
 * {
 *     return self::SUBSCRIPTIONS;
 * }
 * ```
 */
```

### Global Extension Impact
**Rating**: 🟡 MODERATE CONCERN

**Issue**: Global extensions run on every request. The documentation explains this, but doesn't warn about performance implications.

**Enhancement**: Add performance warning to `isGlobal()` documentation:
```php
/**
 * Whether this extension runs on ALL requests.
 *
 * Global extensions (e.g., tracing, metrics) run regardless of whether
 * the client explicitly includes them in request.extensions[].
 *
 * Non-global extensions only run when the client opts in.
 *
 * PERFORMANCE WARNING: Global extensions execute on every request.
 * Ensure handlers are lightweight and add minimal overhead (<1ms).
 * Use non-global mode for expensive operations that clients should
 * opt into explicitly.
 *
 * @return bool True if this extension runs globally
 */
```

## Maintainability Assessment

### API Stability
**Rating**: 🟡 MODERATE RISK

**Concern**: The event subscription array structure is documented but not enforced by types. Future changes to this structure would require updates across all extensions.

**Mitigation**:
1. Use value objects (as suggested in Type Safety section)
2. Add validation during extension registration
3. Document the structure as part of a versioned extension API contract

### Testing Challenges
**Rating**: 🟡 MODERATE

**Issue**: Testing extensions requires understanding event lifecycle, priority handling, and event propagation rules.

**Recommendation**: Provide test helpers:
```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Testing;

trait ExtensionTestHelpers
{
    protected function assertExtensionSubscribesToEvent(
        ExtensionInterface $extension,
        string $eventClass
    ): void {
        $subscriptions = $extension->getSubscribedEvents();

        $this->assertArrayHasKey(
            $eventClass,
            $subscriptions,
            "Extension does not subscribe to {$eventClass}"
        );

        $this->assertArrayHasKey('priority', $subscriptions[$eventClass]);
        $this->assertArrayHasKey('method', $subscriptions[$eventClass]);
        $this->assertIsInt($subscriptions[$eventClass]['priority']);
        $this->assertIsString($subscriptions[$eventClass]['method']);
    }

    protected function assertExtensionHandlerExists(
        ExtensionInterface $extension,
        string $eventClass
    ): void {
        $subscriptions = $extension->getSubscribedEvents();
        $method = $subscriptions[$eventClass]['method'] ?? null;

        $this->assertNotNull($method, "No handler defined for {$eventClass}");
        $this->assertTrue(
            method_exists($extension, $method),
            "Handler method {$method} does not exist on " . get_class($extension)
        );
    }
}
```

## Architectural Considerations

### Design Pattern: Observer with Priorities
**Rating**: 🟢 EXCELLENT

The interface implements a sophisticated observer pattern with prioritized event handling. This is excellent for building a plugin architecture.

### Lifecycle Event Documentation
**Rating**: 🟢 EXCELLENT

The documentation clearly defines the event lifecycle (lines 25-30):
1. RequestValidated
2. ExecutingFunction
3. FunctionExecuted
4. SendingResponse

This is critical architectural documentation that should be preserved and tested.

### Extension Composition
**Rating**: 🔵 ENHANCEMENT OPPORTUNITY

**Issue**: No guidance on extension composition or dependencies between extensions.

**Enhancement**: Add to documentation:
```php
/**
 * Forrst extension contract interface.
 *
 * Defines the contract for implementing Forrst protocol extensions that add
 * optional capabilities to RPC servers without modifying the core protocol.
 * Extensions hook into the request lifecycle via event subscriptions.
 *
 * EXTENSION DEPENDENCIES:
 * Extensions may depend on other extensions being registered. Use priorities
 * to ensure correct execution order. Document dependencies in extension
 * documentation.
 *
 * Example: The "async-result-stream" extension depends on both "async" and
 * "stream" extensions being registered. Set priorities accordingly:
 * - async: priority 40
 * - stream: priority 41
 * - async-result-stream: priority 42
 *
 * Lifecycle events (in order):
 * - RequestValidated: After parsing, before function resolution
 * - ExecutingFunction: Before function dispatch (can short-circuit)
 * - FunctionExecuted: After function returns (can modify response)
 * - SendingResponse: Before serialization (final modifications)
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see ExtensionEvent Base event class for all extension events
 * @see https://docs.cline.sh/forrst/extensions/ Extensions overview
 */
```

## Missing Functionality

### 🔵 Suggestion: Extension Metadata

**Enhancement**: Add methods for richer extension metadata:
```php
/**
 * Get extension metadata for documentation and tooling.
 *
 * @return array{name: string, version: string, description: string, author?: string, license?: string}
 */
public function getMetadata(): array;

/**
 * Get extension dependencies.
 *
 * @return array<int, string> Array of required extension URNs
 */
public function getDependencies(): array;
```

### 🔵 Suggestion: Configuration Schema

**Enhancement**: Add configuration schema support:
```php
/**
 * Get configuration schema for this extension.
 *
 * Defines the structure and validation rules for extension configuration.
 *
 * @return array<string, mixed> JSON Schema compatible array
 */
public function getConfigurationSchema(): array;
```

## Recommendations Summary

### 🟠 High Priority (Address Before Production)

1. **Add Method Existence Validation**: Implement runtime validation in the event dispatcher to verify that handler methods referenced in `getSubscribedEvents()` actually exist and are callable. Add this code to `/Users/brian/Developer/cline/forrst/src/Extensions/ExtensionEventSubscriber.php`:

```php
// In ExtensionEventSubscriber.php or wherever extensions are registered
public function register(ExtensionInterface $extension): void
{
    foreach ($extension->getSubscribedEvents() as $eventClass => $config) {
        // Validate event class exists
        if (!class_exists($eventClass)) {
            throw new \InvalidArgumentException(
                sprintf('Event class %s does not exist', $eventClass)
            );
        }

        // Validate subscription config structure
        if (!isset($config['priority']) || !isset($config['method'])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Event subscription for %s must include "priority" and "method" keys',
                    $eventClass
                )
            );
        }

        // Validate handler method exists and is callable
        $method = $config['method'];
        if (!method_exists($extension, $method)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Extension %s references non-existent method %s for event %s',
                    get_class($extension),
                    $method,
                    $eventClass
                )
            );
        }

        if (!is_callable([$extension, $method])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Method %s::%s is not callable (check visibility)',
                    get_class($extension),
                    $method
                )
            );
        }

        // Validate priority is an integer
        if (!is_int($config['priority'])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Priority for %s::%s must be an integer, %s given',
                    $eventClass,
                    $method,
                    gettype($config['priority'])
                )
            );
        }
    }

    // Continue with registration...
}
```

### 🟡 Medium Priority (Improve Type Safety)

2. **Create Value Objects for Structured Returns**: Create dedicated classes for event subscriptions and capabilities:

```php
// In /Users/brian/Developer/cline/forrst/src/Extensions/EventSubscription.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Extensions;

final readonly class EventSubscription
{
    public function __construct(
        public int $priority,
        public string $method,
    ) {
        if ($priority < 0) {
            throw new \InvalidArgumentException('Priority must be non-negative');
        }

        if (empty($method)) {
            throw new \InvalidArgumentException('Method name cannot be empty');
        }
    }

    public function toArray(): array
    {
        return [
            'priority' => $this->priority,
            'method' => $this->method,
        ];
    }
}
```

```php
// In /Users/brian/Developer/cline/forrst/src/Extensions/ExtensionCapability.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Extensions;

final readonly class ExtensionCapability
{
    public function __construct(
        public string $urn,
        public ?string $documentation = null,
    ) {
        if (empty($urn)) {
            throw new \InvalidArgumentException('URN cannot be empty');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            'urn' => $this->urn,
            'documentation' => $this->documentation,
        ], fn($value) => $value !== null);
    }
}
```

Then update the interface:
```php
/**
 * @return array<class-string<ExtensionEvent>, EventSubscription>
 */
public function getSubscribedEvents(): array;

public function toCapabilities(): ExtensionCapability;
```

3. **Add Performance Documentation**: Document performance expectations for global extensions in the `isGlobal()` method docblock.

### 🔵 Low Priority (Future Enhancements)

4. **Add Usage Example to getSubscribedEvents()**: Include a complete code example showing how to implement event subscriptions.

5. **Create Extension Testing Helpers**: Provide trait or base test class with helper methods for testing extensions.

6. **Consider Extension Dependencies**: Add methods or documentation for declaring and validating extension dependencies.

## Overall Assessment

**Quality Rating**: 🟢 EXCELLENT (8.8/10)

**Strengths**:
- Exceptionally thorough documentation
- Clear event lifecycle definition
- Well-designed priority system
- Excellent separation of concerns
- Comprehensive coverage of extension behavior

**Weaknesses**:
- Array-based return types lack compile-time safety
- Missing runtime validation for method existence
- No formal dependency declaration mechanism
- Performance implications of global extensions could be clearer

**Critical Issue**:
The method name injection in `getSubscribedEvents()` needs runtime validation to prevent errors.

**Recommendation**: ✅ **APPROVED CONDITIONALLY**

Approve for production use with the requirement that method existence validation is implemented in the event dispatcher. The other suggestions are enhancements that can be prioritized based on team needs.

The interface is fundamentally well-designed and provides an excellent foundation for a plugin architecture. The priority-based event system is sophisticated and well-documented. Adding runtime validation and value objects will elevate this from excellent to exceptional.

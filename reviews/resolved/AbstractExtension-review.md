# Code Review: AbstractExtension.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Extensions/AbstractExtension.php`
**Purpose:** Base abstract class for Forrst extension handlers, providing default implementations for extension behavior and contracts.

---

## Executive Summary

AbstractExtension serves as the foundation for all extension handlers in the Forrst protocol. It implements the `ExtensionInterface` contract and provides sensible defaults while requiring subclasses to implement the core `getUrn()` method. The class is well-documented and follows solid OOP principles, but there are several areas where code quality, type safety, and architectural design could be improved.

**Severity Breakdown:**
- Critical: 0 issues
- Major: 2 issues
- Minor: 3 issues
- Suggestions: 2 improvements

---

## SOLID Principles Analysis

### Single Responsibility Principle (SRP): PASS
The class has a single, well-defined responsibility: providing default behavior for extension handlers. Each method handles one specific aspect of extension configuration (global status, error fatality, event subscriptions, capability export).

### Open/Closed Principle (OCP): PASS
The class is designed for extension through inheritance. Protected/public methods allow subclasses to override behavior while the abstract `getUrn()` method enforces implementation of required functionality.

### Liskov Substitution Principle (LSP): PASS
Any subclass of AbstractExtension can be substituted wherever ExtensionInterface is expected without breaking behavior.

### Interface Segregation Principle (ISP): PASS
The class implements only the ExtensionInterface, which appears appropriately sized for its purpose.

### Dependency Inversion Principle (DIP): PASS
The class depends on the abstraction (ExtensionInterface) rather than concrete implementations.

---

## Critical Issues

**None identified.**

---

## Major Issues

### 🟠 Major Issue #1: Missing Type Declaration for Abstract Method (Line: Abstract method not present)

**Location:** Throughout the class
**Impact:** The abstract class does not declare the required `getUrn()` method, relying on documentation alone. This creates a runtime risk where developers might forget to implement it, leading to fatal errors only at execution time rather than during static analysis.

**Current Code:**
```php
abstract class AbstractExtension implements ExtensionInterface
{
    // ... methods ...

    // Missing: abstract public function getUrn(): string;
}
```

**Solution:**
```php
abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * Get the unique URN identifier for this extension.
     *
     * Subclasses must implement this to return their unique identifier used
     * in extension discovery and request routing.
     *
     * @return string Extension URN (e.g., 'forrst.ext.async')
     */
    abstract public function getUrn(): string;

    /**
     * Determine if extension runs on all requests.
     *
     * By default, extensions are opt-in and only run when explicitly requested
     * in the request's extensions array. Override to return true for extensions
     * that should run globally (e.g., tracing, monitoring).
     *
     * @return bool False by default (opt-in mode)
     */
    public function isGlobal(): bool
    {
        return false;
    }

    // ... rest of methods
}
```

**Why This Matters:**
Without the abstract method declaration, PHP won't enforce implementation at compile/parse time. Static analyzers like PHPStan will also miss this requirement, potentially allowing incomplete extensions to be deployed.

---

### 🟠 Major Issue #2: Insufficient Type Safety in Event Subscriptions (Line 74)

**Location:** `getSubscribedEvents()` method, line 74
**Impact:** The return type `array<class-string, array{priority: int, method: string}>` doesn't validate that the method name actually exists on the class, creating a runtime risk of method-not-found errors.

**Current Code:**
```php
/**
 * @return array<class-string, array{priority: int, method: string}> Event subscriptions
 */
public function getSubscribedEvents(): array
{
    return [];
}
```

**Solution:**
Consider adding a validation layer or using a more structured approach with value objects:

```php
<?php
namespace Cline\Forrst\Extensions;

use Cline\Forrst\Events\EventSubscription;

abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * Get event subscriptions for this extension.
     *
     * Subclasses should override this to subscribe to lifecycle events such as
     * RequestValidated, ExecutingFunction, FunctionExecuted, or SendingResponse.
     *
     * @return array<EventSubscription> Event subscriptions with type safety
     */
    public function getSubscribedEvents(): array
    {
        return [];
    }
}

// Create EventSubscription value object:
// File: src/Events/EventSubscription.php
<?php
namespace Cline\Forrst\Events;

final readonly class EventSubscription
{
    public function __construct(
        public string $eventClass,
        public string $method,
        public int $priority = 0,
    ) {
        if (!class_exists($this->eventClass)) {
            throw new \InvalidArgumentException(
                "Event class {$this->eventClass} does not exist"
            );
        }

        // Validate method exists in runtime through reflection if needed
    }

    public static function create(
        string $eventClass,
        string $method,
        int $priority = 0
    ): self {
        return new self($eventClass, $method, $priority);
    }
}
```

**Alternative simpler solution** (if value objects are too heavy):

```php
/**
 * Validate event subscription configuration.
 *
 * @param array<class-string, array{priority: int, method: string}> $subscriptions
 * @throws \InvalidArgumentException If method doesn't exist
 */
protected function validateEventSubscriptions(array $subscriptions): void
{
    foreach ($subscriptions as $eventClass => $config) {
        if (!method_exists($this, $config['method'])) {
            throw new \InvalidArgumentException(sprintf(
                'Event handler method %s::%s() does not exist',
                static::class,
                $config['method']
            ));
        }
    }
}

public function getSubscribedEvents(): array
{
    $subscriptions = $this->doGetSubscribedEvents();
    $this->validateEventSubscriptions($subscriptions);
    return $subscriptions;
}

/**
 * Override this method in subclasses to define event subscriptions.
 *
 * @return array<class-string, array{priority: int, method: string}>
 */
protected function doGetSubscribedEvents(): array
{
    return [];
}
```

**Why This Matters:**
Runtime method-not-found errors are difficult to debug and can cause production failures. Adding validation catches configuration errors early during testing or deployment.

---

## Minor Issues

### 🟡 Minor Issue #1: Documentation Redundancy (Lines 21-29)

**Location:** Class docblock, lines 21-29
**Impact:** The docblock contains significant redundancy, repeating information about extension behavior twice. This increases maintenance burden and can lead to inconsistencies.

**Current Code:**
```php
/**
 * Base implementation for Forrst extension handlers.
 *
 * Provides default behavior and contracts for extension handlers that add
 * optional capabilities to the Forrst protocol such as caching, async operations,
 * deadlines, and deprecation warnings.
 *
 * Provides sensible defaults for extension behavior while requiring subclasses
 * to implement the core identification method (getUrn). Extensions add optional
 * capabilities to the Forrst protocol such as caching, async operations, deadlines,
 * and deprecation warnings.
 *
 * Default behavior:
 * - Not global: extension only runs when explicitly requested by clients
 * - Fatal errors: extension failures stop request processing
 * - No event subscriptions: subclasses must override getSubscribedEvents
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/
 */
```

**Solution:**
```php
/**
 * Base implementation for Forrst extension handlers.
 *
 * Provides sensible defaults for extension behavior while requiring subclasses
 * to implement the core identification method (getUrn). Extensions add optional
 * capabilities to the Forrst protocol such as caching, async operations, deadlines,
 * and deprecation warnings.
 *
 * Default behavior:
 * - Opt-in mode: extension only runs when explicitly requested by clients
 * - Fatal errors: extension failures stop request processing
 * - No event subscriptions: subclasses must override getSubscribedEvents
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/
 */
abstract class AbstractExtension implements ExtensionInterface
{
    // ...
}
```

---

### 🟡 Minor Issue #2: Inconsistent Terminology in Comments (Line 27 vs 40)

**Location:** Lines 27 and 40
**Impact:** The class docblock uses "Not global" while the method docblock uses "opt-in mode" to describe the same concept. Inconsistent terminology makes the codebase harder to understand.

**Solution:**
Standardize on one term throughout. I recommend "opt-in mode" as it's more descriptive:

```php
/**
 * Default behavior:
 * - Opt-in mode: extension only runs when explicitly requested by clients
 * - Fatal errors: extension failures stop request processing
 * - No event subscriptions: subclasses must override getSubscribedEvents
 */
```

---

### 🟡 Minor Issue #3: Missing Validation in toCapabilities (Line 88)

**Location:** `toCapabilities()` method, line 88
**Impact:** The method calls `getUrn()` without validation, which could return an empty string or invalid URN format, potentially breaking capability discovery.

**Current Code:**
```php
public function toCapabilities(): array
{
    return [
        'urn' => $this->getUrn(),
    ];
}
```

**Solution:**
```php
public function toCapabilities(): array
{
    $urn = $this->getUrn();

    if (empty($urn)) {
        throw new \RuntimeException(sprintf(
            'Extension %s returned empty URN from getUrn()',
            static::class
        ));
    }

    // Optional: validate URN format (e.g., must start with 'forrst.ext.')
    if (!str_starts_with($urn, 'forrst.ext.')) {
        throw new \RuntimeException(sprintf(
            'Invalid URN format "%s" from %s: must start with "forrst.ext."',
            $urn,
            static::class
        ));
    }

    return [
        'urn' => $urn,
    ];
}
```

**Why This Matters:**
Failing fast with a clear error message during capability export is better than returning malformed data that causes subtle bugs in discovery mechanisms.

---

## Suggestions

### 🔵 Suggestion #1: Add Helper Method for Extension Metadata

**Location:** General class improvement
**Benefit:** Subclasses often need to provide additional metadata beyond just the URN (version, documentation URL, author, etc.). Adding a protected helper makes this easier.

**Implementation:**
```php
abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * Get additional capability metadata.
     *
     * Subclasses can override this to provide documentation URLs, version info,
     * or other metadata for inclusion in capability responses.
     *
     * @return array<string, mixed> Additional metadata
     */
    protected function getCapabilityMetadata(): array
    {
        return [];
    }

    /**
     * Export extension capabilities for discovery.
     *
     * Returns the extension's URN for inclusion in server capabilities responses.
     * Subclasses can override getCapabilityMetadata() to include additional information.
     *
     * @return array{urn: string, documentation?: string} Capability information
     */
    final public function toCapabilities(): array
    {
        return array_merge(
            ['urn' => $this->getUrn()],
            $this->getCapabilityMetadata()
        );
    }
}

// Usage in subclass:
final class AsyncExtension extends AbstractExtension
{
    protected function getCapabilityMetadata(): array
    {
        return [
            'documentation' => 'https://docs.cline.sh/forrst/extensions/async',
            'version' => '1.0.0',
            'author' => 'Cline Team',
        ];
    }
}
```

---

### 🔵 Suggestion #2: Consider Adding Extension Configuration Support

**Location:** Constructor
**Benefit:** Many extensions need configuration (cache TTLs, rate limits, etc.). Adding configuration support to the base class provides a consistent pattern.

**Implementation:**
```php
abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * Create a new extension instance.
     *
     * @param array<string, mixed> $config Extension-specific configuration
     */
    public function __construct(
        protected readonly array $config = []
    ) {}

    /**
     * Get a configuration value with optional default.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Check if a configuration key exists.
     *
     * @param string $key Configuration key
     * @return bool True if key exists
     */
    protected function hasConfig(string $key): bool
    {
        return array_key_exists($key, $this->config);
    }
}

// Usage in subclass:
final class CachingExtension extends AbstractExtension
{
    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    private function getTtl(): int
    {
        return $this->config('ttl', 3600);
    }
}
```

---

## Security Analysis

**No security vulnerabilities identified.** This is an abstract infrastructure class that doesn't handle user input or sensitive data directly.

---

## Performance Considerations

**No significant performance concerns.** All methods are simple getters or configuration methods with O(1) complexity.

---

## Testing Recommendations

1. **Test abstract method enforcement:** Verify that subclasses without `getUrn()` implementation fail appropriately
2. **Test event subscription validation:** Ensure invalid method names in `getSubscribedEvents()` are caught
3. **Test capability export:** Verify `toCapabilities()` returns correctly formatted data
4. **Test configuration patterns:** If configuration support is added, test default values and overrides

**Example test:**
```php
<?php

use PHPUnit\Framework\TestCase;

final class AbstractExtensionTest extends TestCase
{
    public function test_subclass_must_implement_get_urn(): void
    {
        $this->expectException(\Error::class);

        // This should fail because TestExtension doesn't implement getUrn()
        new class extends AbstractExtension {};
    }

    public function test_to_capabilities_includes_urn(): void
    {
        $extension = new class extends AbstractExtension {
            public function getUrn(): string
            {
                return 'forrst.ext.test';
            }
        };

        $capabilities = $extension->toCapabilities();

        $this->assertArrayHasKey('urn', $capabilities);
        $this->assertEquals('forrst.ext.test', $capabilities['urn']);
    }

    public function test_default_is_not_global(): void
    {
        $extension = new class extends AbstractExtension {
            public function getUrn(): string { return 'test'; }
        };

        $this->assertFalse($extension->isGlobal());
    }

    public function test_default_errors_are_fatal(): void
    {
        $extension = new class extends AbstractExtension {
            public function getUrn(): string { return 'test'; }
        };

        $this->assertTrue($extension->isErrorFatal());
    }
}
```

---

## Conclusion

AbstractExtension is a well-designed base class that provides good defaults and clear extension points. The main improvements needed are:

1. **Add abstract method declaration** for `getUrn()` to enforce implementation at parse time
2. **Improve type safety** in event subscriptions through validation or value objects
3. **Clean up documentation** to remove redundancy
4. **Add URN validation** in `toCapabilities()`

These changes will improve type safety, developer experience, and runtime reliability while maintaining backward compatibility. The class already follows SOLID principles well and serves its architectural purpose effectively.

**Overall Grade: B+** (Good design with room for improvement in type safety and validation)

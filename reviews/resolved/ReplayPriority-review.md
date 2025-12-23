# Code Review: ReplayPriority.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Enums/ReplayPriority.php`

**Purpose:** Defines priority levels for replay operations in the Forrst replay extension, controlling execution order when multiple replays are queued.

---

## Executive Summary

The `ReplayPriority` enum is a clean, minimal implementation that serves its purpose effectively. While the code quality is high for its current scope, there are significant opportunities to enhance functionality with comparison methods, queue position calculations, and validation helpers that would make this enum more powerful and easier to use throughout the application.

**Strengths:**
- Clear, concise case definitions
- Well-documented with appropriate PHPDoc
- Simple string-backed enum implementation
- Follows naming conventions consistently

**Areas for Improvement:**
- Missing comparison methods for priority ordering
- No numeric weight/score representation
- Lacks helper methods for queue management
- No validation or parsing utilities
- Missing integration points for queue systems

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) - EXCELLENT
The enum has a single, focused responsibility: representing priority levels for replay operations. No extraneous concerns are mixed in.

**Score: 10/10**

### Open/Closed Principle (OCP) - GOOD
The enum is open for extension through new priority levels, though adding cases would require updating any comparison logic. This is inherent to the enum pattern.

**Score: 8/10**

**Recommendation:** Implement comparison methods that can handle future priority additions gracefully.

### Liskov Substitution Principle (LSP) - N/A
Not applicable to enum implementations.

### Interface Segregation Principle (ISP) - EXCELLENT
The enum provides a minimal interface appropriate for a simple priority representation.

**Score: 10/10**

### Dependency Inversion Principle (DIP) - EXCELLENT
No dependencies on concrete implementations or infrastructure concerns.

**Score: 10/10**

---

## Code Quality Issues

### 🟠 Major Issue #1: Missing Priority Comparison Methods

**Issue:** The enum lacks any methods to compare priorities, which is essential for queue management and scheduling logic. Developers will need to implement custom comparison logic throughout the codebase.

**Location:** Entire file (missing functionality)

**Impact:**
- Code duplication when comparing priorities
- Potential for inconsistent ordering logic
- Difficulty maintaining queue-based systems
- Error-prone manual comparisons

**Solution:**
```php
// Add to ReplayPriority.php after line 48:

/**
 * Get the numeric weight for this priority level.
 *
 * Higher values indicate higher priority. Used for queue sorting
 * and priority comparisons. Values are spaced to allow future
 * priority levels to be inserted between existing ones.
 *
 * @return int Priority weight (10=low, 50=normal, 90=high)
 */
public function getWeight(): int
{
    return match ($this) {
        self::High => 90,
        self::Normal => 50,
        self::Low => 10,
    };
}

/**
 * Compare this priority with another priority.
 *
 * Returns a negative number if this priority is lower, zero if equal,
 * or a positive number if this priority is higher. Compatible with
 * usort() and other comparison-based sorting functions.
 *
 * @param self $other Priority to compare against
 * @return int Comparison result: negative (lower), 0 (equal), positive (higher)
 */
public function compareTo(self $other): int
{
    return $this->getWeight() <=> $other->getWeight();
}

/**
 * Check if this priority is higher than another priority.
 *
 * @param self $other Priority to compare against
 * @return bool True if this priority should be processed before the other
 */
public function isHigherThan(self $other): bool
{
    return $this->getWeight() > $other->getWeight();
}

/**
 * Check if this priority is lower than another priority.
 *
 * @param self $other Priority to compare against
 * @return bool True if this priority should be processed after the other
 */
public function isLowerThan(self $other): bool
{
    return $this->getWeight() < $other->getWeight();
}

/**
 * Check if this priority is the same as another priority.
 *
 * @param self $other Priority to compare against
 * @return bool True if priorities are equal
 */
public function isEqualTo(self $other): bool
{
    return $this === $other;
}
```

**Usage:**
```php
$highPriority = ReplayPriority::High;
$normalPriority = ReplayPriority::Normal;

if ($highPriority->isHigherThan($normalPriority)) {
    // Process high priority first
}

// Sort replays by priority
usort($replays, fn($a, $b) => $b->priority->compareTo($a->priority));
```

### 🟡 Minor Issue #1: Missing Default Priority Helper

**Issue:** No method to retrieve the default priority level, forcing developers to hardcode `ReplayPriority::Normal` throughout the codebase.

**Location:** Entire file (missing functionality)

**Impact:**
- Code duplication of default value
- Difficult to change default behavior
- Unclear what the default priority should be

**Solution:**
```php
// Add to ReplayPriority.php:

/**
 * Get the default priority level.
 *
 * Returns the standard priority used when no explicit priority is
 * specified. Centralizes the default value for consistency across
 * the application.
 *
 * @return self The default priority level (Normal)
 */
public static function default(): self
{
    return self::Normal;
}
```

**Usage:**
```php
// Instead of:
$priority = $request->priority ?? ReplayPriority::Normal;

// Use:
$priority = $request->priority ?? ReplayPriority::default();
```

### 🟡 Minor Issue #2: Missing Priority Validation and Parsing

**Issue:** No helpers to validate or parse priority strings from external sources (API requests, config files, etc.).

**Location:** Entire file (missing functionality)

**Impact:**
- Inconsistent validation logic across controllers/services
- Difficult to provide user-friendly error messages
- No centralized parsing logic

**Solution:**
```php
// Add to ReplayPriority.php:

/**
 * Parse a priority string to a ReplayPriority enum case.
 *
 * Attempts to create a ReplayPriority from a string value with
 * case-insensitive matching. Returns null if the value doesn't
 * match any valid priority level.
 *
 * @param string $value Priority value to parse (e.g., 'high', 'NORMAL', 'Low')
 * @return null|self Matched priority or null if invalid
 */
public static function tryFrom(string $value): ?self
{
    $normalized = strtolower($value);

    return match ($normalized) {
        'high' => self::High,
        'normal' => self::Normal,
        'low' => self::Low,
        default => null,
    };
}

/**
 * Parse a priority string or return the default priority.
 *
 * Convenience method combining tryFrom() with a default fallback.
 * Useful for parsing optional priority values from requests.
 *
 * @param null|string $value Priority value to parse (null returns default)
 * @return self Parsed priority or default priority
 */
public static function fromOrDefault(?string $value): self
{
    if ($value === null) {
        return self::default();
    }

    return self::tryFrom($value) ?? self::default();
}

/**
 * Get all valid priority values as strings.
 *
 * Returns an array of valid string values for validation, documentation,
 * or UI dropdown generation. Values are lowercase to match API convention.
 *
 * @return array<string> Valid priority values ['high', 'normal', 'low']
 */
public static function values(): array
{
    return array_map(
        fn(self $case) => $case->value,
        self::cases()
    );
}
```

**Usage:**
```php
// API request validation
$priority = ReplayPriority::tryFrom($request->input('priority'));
if ($priority === null) {
    return response()->json([
        'error' => 'Invalid priority. Valid values: ' . implode(', ', ReplayPriority::values())
    ], 400);
}

// With default fallback
$priority = ReplayPriority::fromOrDefault($request->input('priority'));
```

### 🔵 Suggestion #1: Add Queue Position Calculation

**Issue:** For debugging and monitoring, it would be helpful to calculate expected queue positions based on priority.

**Location:** Entire file (enhancement)

**Impact:** Improves observability and debugging of queue behavior.

**Solution:**
```php
// Add to ReplayPriority.php:

/**
 * Get a human-readable label for this priority.
 *
 * Provides a display-friendly name for UI elements, logs, and
 * notifications. Capitalized for presentation.
 *
 * @return string Human-readable priority label
 */
public function label(): string
{
    return match ($this) {
        self::High => 'High Priority',
        self::Normal => 'Normal Priority',
        self::Low => 'Low Priority',
    };
}

/**
 * Get an icon or emoji representing this priority.
 *
 * Useful for UI elements, notifications, and logs to quickly
 * identify priority levels visually.
 *
 * @return string Icon/emoji representing the priority
 */
public function icon(): string
{
    return match ($this) {
        self::High => '🔴',
        self::Normal => '🟡',
        self::Low => '🟢',
    };
}

/**
 * Get CSS class name for this priority.
 *
 * Provides standard CSS class names for consistent UI styling
 * across the application (e.g., badge colors, text colors).
 *
 * @return string CSS class name (e.g., 'priority-high')
 */
public function cssClass(): string
{
    return match ($this) {
        self::High => 'priority-high',
        self::Normal => 'priority-normal',
        self::Low => 'priority-low',
    };
}
```

---

## Security Vulnerabilities

### No Direct Security Issues

The enum itself doesn't introduce security vulnerabilities. However, there are security-adjacent concerns:

### 🟡 Minor Concern: Priority Escalation Risk

**Issue:** Without proper validation in the application layer, clients could potentially abuse high priority to skip queues.

**Location:** Application usage (not in this file)

**Impact:** Resource exhaustion, unfair queue processing, potential DoS

**Recommendation:**
```php
// In the application layer handling priority assignment:

/**
 * Validate that the user has permission to set this priority level.
 *
 * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
 */
public function validatePriorityPermission(User $user, ReplayPriority $priority): void
{
    // Only admins can use high priority
    if ($priority === ReplayPriority::High && !$user->isAdmin()) {
        throw new AccessDeniedHttpException(
            'High priority replays require administrator privileges'
        );
    }

    // Rate limit high priority usage even for admins
    if ($priority === ReplayPriority::High) {
        RateLimiter::for('high-priority-replay', function (Request $request) {
            return Limit::perHour(10)->by($request->user()->id);
        });
    }
}
```

---

## Performance Concerns

### Excellent Performance Profile

**No performance issues.** The implementation is optimal:

1. **Enum Overhead:** Minimal - enums are singleton objects
2. **String Backing:** Efficient for serialization and database storage
3. **No Complex Logic:** All operations are constant time O(1)

**Proposed Additions Performance:**
- `getWeight()`: O(1) - single match expression
- `compareTo()`: O(1) - single integer comparison
- `isHigherThan()`: O(1) - single comparison
- `tryFrom()`: O(1) - single match expression
- `values()`: O(n) where n=3 - trivial overhead

**Memory:** ~32-64 bytes per enum instance (singleton pattern means only 3 instances exist total)

---

## Maintainability Assessment

### Good Maintainability - Score: 7.5/10

**Strengths:**
1. Clear, simple implementation
2. Good documentation for existing functionality
3. Consistent naming conventions
4. Easy to understand at a glance

**Weaknesses:**
1. Limited functionality requires external implementations
2. No built-in comparison capabilities
3. Missing validation and parsing helpers
4. Would benefit from more helper methods

**Improvement Recommendations:**

1. **Add comprehensive test suite:**
```php
// tests/Unit/Enums/ReplayPriorityTest.php

namespace Tests\Unit\Enums;

use Cline\Forrst\Enums\ReplayPriority;
use PHPUnit\Framework\TestCase;

final class ReplayPriorityTest extends TestCase
{
    /** @test */
    public function it_has_three_priority_levels(): void
    {
        $priorities = ReplayPriority::cases();

        $this->assertCount(3, $priorities);
        $this->assertContains(ReplayPriority::High, $priorities);
        $this->assertContains(ReplayPriority::Normal, $priorities);
        $this->assertContains(ReplayPriority::Low, $priorities);
    }

    /** @test */
    public function it_has_correct_string_values(): void
    {
        $this->assertSame('high', ReplayPriority::High->value);
        $this->assertSame('normal', ReplayPriority::Normal->value);
        $this->assertSame('low', ReplayPriority::Low->value);
    }

    /** @test */
    public function high_priority_has_higher_weight_than_normal(): void
    {
        $this->assertGreaterThan(
            ReplayPriority::Normal->getWeight(),
            ReplayPriority::High->getWeight()
        );
    }

    /** @test */
    public function normal_priority_has_higher_weight_than_low(): void
    {
        $this->assertGreaterThan(
            ReplayPriority::Low->getWeight(),
            ReplayPriority::Normal->getWeight()
        );
    }

    /** @test */
    public function it_compares_priorities_correctly(): void
    {
        $this->assertTrue(ReplayPriority::High->isHigherThan(ReplayPriority::Normal));
        $this->assertTrue(ReplayPriority::High->isHigherThan(ReplayPriority::Low));
        $this->assertTrue(ReplayPriority::Normal->isHigherThan(ReplayPriority::Low));

        $this->assertFalse(ReplayPriority::Low->isHigherThan(ReplayPriority::Normal));
        $this->assertFalse(ReplayPriority::Normal->isHigherThan(ReplayPriority::High));
    }

    /** @test */
    public function it_sorts_by_priority_descending(): void
    {
        $priorities = [
            ReplayPriority::Low,
            ReplayPriority::High,
            ReplayPriority::Normal,
        ];

        usort($priorities, fn($a, $b) => $b->compareTo($a));

        $this->assertSame(
            [ReplayPriority::High, ReplayPriority::Normal, ReplayPriority::Low],
            $priorities
        );
    }

    /** @test */
    public function default_priority_is_normal(): void
    {
        $this->assertSame(ReplayPriority::Normal, ReplayPriority::default());
    }

    /** @test */
    public function it_parses_priority_strings_case_insensitively(): void
    {
        $this->assertSame(ReplayPriority::High, ReplayPriority::tryFrom('high'));
        $this->assertSame(ReplayPriority::High, ReplayPriority::tryFrom('HIGH'));
        $this->assertSame(ReplayPriority::Normal, ReplayPriority::tryFrom('normal'));
        $this->assertSame(ReplayPriority::Low, ReplayPriority::tryFrom('low'));
    }

    /** @test */
    public function it_returns_null_for_invalid_priority_strings(): void
    {
        $this->assertNull(ReplayPriority::tryFrom('invalid'));
        $this->assertNull(ReplayPriority::tryFrom('medium'));
        $this->assertNull(ReplayPriority::tryFrom(''));
    }

    /** @test */
    public function it_returns_default_for_null_or_invalid_values(): void
    {
        $this->assertSame(ReplayPriority::Normal, ReplayPriority::fromOrDefault(null));
        $this->assertSame(ReplayPriority::Normal, ReplayPriority::fromOrDefault('invalid'));
    }

    /** @test */
    public function it_provides_all_valid_values(): void
    {
        $values = ReplayPriority::values();

        $this->assertSame(['high', 'normal', 'low'], $values);
    }

    /** @test */
    public function it_provides_human_readable_labels(): void
    {
        $this->assertSame('High Priority', ReplayPriority::High->label());
        $this->assertSame('Normal Priority', ReplayPriority::Normal->label());
        $this->assertSame('Low Priority', ReplayPriority::Low->label());
    }
}
```

2. **Document priority behavior in queue context:**
```php
/**
 * Priority levels for replay operations in the Forrst replay extension.
 *
 * Defines execution priority for queued replay operations. Higher priority
 * replays are processed before lower priority ones when multiple replays
 * are queued. Priority affects scheduling order but does not guarantee
 * immediate execution.
 *
 * Queue Behavior:
 * - High priority: Processed first, skips ahead of normal and low priority
 * - Normal priority: Processed in FIFO order after high priority
 * - Low priority: Processed last, only when higher priorities are empty
 *
 * Priority does NOT:
 * - Interrupt currently executing replays
 * - Guarantee immediate execution (subject to concurrency limits)
 * - Override resource quotas or rate limits
 *
 * Use Cases:
 * - High: Time-sensitive operations, user-facing retries, critical workflows
 * - Normal: Standard background jobs, automated replays, scheduled tasks
 * - Low: Batch operations, cleanup tasks, non-urgent background work
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/replay
 */
```

---

## Additional Recommendations

### 1. Integration with Queue Systems

Add factory methods for common queue integrations:

```php
// Add to ReplayPriority.php:

/**
 * Convert priority to Laravel queue priority value.
 *
 * Maps ReplayPriority to Laravel's queue priority system for
 * consistent priority handling across queue backends.
 *
 * @return int Laravel queue priority (1-10, higher is higher priority)
 */
public function toQueuePriority(): int
{
    return match ($this) {
        self::High => 10,
        self::Normal => 5,
        self::Low => 1,
    };
}

/**
 * Create from Laravel queue priority value.
 *
 * Reverse mapping from Laravel queue priorities to ReplayPriority.
 * Useful when deserializing jobs from the queue.
 *
 * @param int $queuePriority Laravel queue priority (1-10)
 * @return self Closest matching ReplayPriority
 */
public static function fromQueuePriority(int $queuePriority): self
{
    return match (true) {
        $queuePriority >= 8 => self::High,
        $queuePriority >= 3 => self::Normal,
        default => self::Low,
    };
}
```

### 2. Add Metrics/Monitoring Support

```php
// Add to ReplayPriority.php:

/**
 * Get metric tags for priority tracking.
 *
 * Provides standardized tags for metrics collection, useful for
 * monitoring queue depth, processing times, and priority distribution.
 *
 * @return array{priority: string, weight: int, label: string}
 */
public function getMetricTags(): array
{
    return [
        'priority' => $this->value,
        'weight' => $this->getWeight(),
        'label' => $this->label(),
    ];
}
```

### 3. Configuration Support

```php
// Add to config/forrst.php:

return [
    'replay' => [
        'priorities' => [
            // Maximum number of concurrent high priority replays
            'high_concurrency' => env('FORRST_HIGH_PRIORITY_CONCURRENCY', 5),

            // Maximum number of concurrent normal priority replays
            'normal_concurrency' => env('FORRST_NORMAL_PRIORITY_CONCURRENCY', 10),

            // Maximum number of concurrent low priority replays
            'low_concurrency' => env('FORRST_LOW_PRIORITY_CONCURRENCY', 3),

            // Default priority when none specified
            'default' => env('FORRST_DEFAULT_PRIORITY', 'normal'),

            // Require elevated permissions for high priority
            'high_requires_admin' => env('FORRST_HIGH_PRIORITY_ADMIN_ONLY', true),
        ],
    ],
];
```

### 4. API Documentation Enhancement

Create an OpenAPI/Swagger schema fragment:

```yaml
# docs/openapi/schemas/ReplayPriority.yaml
ReplayPriority:
  type: string
  enum:
    - high
    - normal
    - low
  default: normal
  description: |
    Priority level for replay operations.

    - **high**: Processed first, before normal and low priority replays
    - **normal**: Default priority, processed in FIFO order after high
    - **low**: Processed last, only when higher priorities are complete

    Note: Priority affects queue ordering but does not guarantee immediate
    execution or interrupt currently executing replays.
  example: normal
```

---

## Conclusion

The `ReplayPriority` enum is a solid foundation that effectively represents priority levels. However, it's currently underutilized and would benefit significantly from additional helper methods for comparison, validation, and integration with queue systems.

**Final Score: 7.5/10**

**Strengths:**
- Clean, simple implementation
- Clear documentation
- Appropriate string backing values
- Good naming conventions

**Critical Improvements Needed:**
1. **Priority Comparison Methods** (Major): Add `getWeight()`, `compareTo()`, `isHigherThan()`, `isLowerThan()`
2. **Validation and Parsing** (Minor): Add `tryFrom()`, `fromOrDefault()`, `values()`
3. **Default Priority** (Minor): Add `default()` static method
4. **Integration Helpers** (Suggestion): Add queue integration, metrics support

**Recommended Next Steps:**
1. Implement all comparison methods (Major Issue #1) - **Priority: HIGH**
2. Add validation and parsing helpers (Minor Issues #1-2) - **Priority: MEDIUM**
3. Create comprehensive test suite covering all methods - **Priority: HIGH**
4. Add integration methods for Laravel queues - **Priority: MEDIUM**
5. Document priority behavior and best practices - **Priority: LOW**

**Implementation Priority:** The comparison methods are critical for proper queue management and should be implemented immediately. Without them, priority ordering logic will be duplicated and potentially inconsistent throughout the codebase.

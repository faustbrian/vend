# Code Review: ReplayStatus.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Enums/ReplayStatus.php`

**Purpose:** Tracks lifecycle status values for replay operations from queuing through terminal states, managing state transitions and lifecycle constraints.

---

## Executive Summary

The `ReplayStatus` enum provides a well-structured state machine for replay operations with clear terminal state handling. The implementation is solid but lacks critical state transition validation, transition history tracking, and helper methods that would prevent invalid state changes and improve observability.

**Strengths:**
- Clear lifecycle states with descriptive documentation
- Terminal state detection via `isTerminal()` method
- Logical state progression (queued → processing → terminal)
- Comprehensive coverage of success and failure scenarios

**Areas for Improvement:**
- Missing state transition validation
- No transition rules enforcement
- Lacks transition history or audit trail support
- Missing helper methods for common state checks
- No duration tracking or SLA monitoring support

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) - EXCELLENT
The enum has a focused responsibility: representing replay operation lifecycle states and identifying terminal states. No extraneous concerns are present.

**Score: 10/10**

### Open/Closed Principle (OCP) - GOOD
The enum is open for extension (new states can be added), but state transition logic would need updates. The `isTerminal()` method would require modification when adding terminal states.

**Score: 8/10**

**Recommendation:** Implement a state transition validator that uses a transition matrix, making it easier to extend.

### Liskov Substitution Principle (LSP) - N/A
Not applicable to enum implementations.

### Interface Segregation Principle (ISP) - GOOD
The interface is minimal with only one method (`isTerminal()`). However, missing methods for common operations forces clients to implement their own logic.

**Score: 8/10**

### Dependency Inversion Principle (DIP) - EXCELLENT
No dependencies on concrete implementations or infrastructure.

**Score: 10/10**

---

## Code Quality Issues

### 🔴 Critical Issue #1: Missing State Transition Validation

**Issue:** The enum provides no mechanism to validate state transitions, allowing invalid transitions like `Completed` → `Processing` or `Failed` → `Queued`. This violates the documented lifecycle constraints.

**Location:** Entire file (missing critical functionality)

**Impact:**
- Data integrity violations
- Invalid replay states in database
- Broken business logic assumptions
- Difficult debugging when invalid states occur
- Potential security issues with unauthorized state manipulation

**Solution:**
```php
// Add to ReplayStatus.php after the isTerminal() method:

/**
 * Validate if transition from this status to another is allowed.
 *
 * Enforces the replay lifecycle state machine by validating proposed
 * transitions against allowed state changes. Terminal states cannot
 * transition to any other state. Non-terminal states have specific
 * allowed transitions based on the replay lifecycle.
 *
 * Valid transition paths:
 * - Queued → Processing, Cancelled, Expired
 * - Processing → Completed, Failed, Cancelled, Expired
 * - Terminal states → No transitions allowed
 *
 * @param self $newStatus The proposed new status
 * @return bool True if the transition is valid, false otherwise
 */
public function canTransitionTo(self $newStatus): bool
{
    // Terminal states cannot transition
    if ($this->isTerminal()) {
        return false;
    }

    // Self-transition is always invalid (status shouldn't change to itself)
    if ($this === $newStatus) {
        return false;
    }

    return match ($this) {
        self::Queued => in_array($newStatus, [
            self::Processing,
            self::Cancelled,
            self::Expired,
        ], true),

        self::Processing => in_array($newStatus, [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired,
            self::Processed,
        ], true),

        // Terminal states already handled above
        default => false,
    };
}

/**
 * Validate and enforce a state transition.
 *
 * Throws an exception if the transition is not valid according to
 * the replay lifecycle rules. Use this when transitioning replay
 * status to ensure state machine integrity.
 *
 * @param self $newStatus The desired new status
 * @throws \Cline\Forrst\Exceptions\InvalidStatusTransitionException
 * @return self The new status if transition is valid
 */
public function transitionTo(self $newStatus): self
{
    if (!$this->canTransitionTo($newStatus)) {
        throw new \Cline\Forrst\Exceptions\InvalidStatusTransitionException(
            sprintf(
                'Invalid status transition from %s to %s. %s',
                $this->value,
                $newStatus->value,
                $this->isTerminal()
                    ? 'Terminal states cannot transition.'
                    : 'This transition is not allowed by the replay lifecycle.'
            )
        );
    }

    return $newStatus;
}

/**
 * Get all valid next states from this status.
 *
 * Returns an array of statuses that are valid transitions from the
 * current status. Useful for UI dropdowns, API documentation, and
 * validation logic.
 *
 * @return array<self> Array of valid next statuses
 */
public function getValidTransitions(): array
{
    if ($this->isTerminal()) {
        return [];
    }

    return match ($this) {
        self::Queued => [
            self::Processing,
            self::Cancelled,
            self::Expired,
        ],

        self::Processing => [
            self::Completed,
            self::Failed,
            self::Cancelled,
            self::Expired,
            self::Processed,
        ],

        default => [],
    };
}
```

**Usage:**
```php
// Validate transition before updating
$currentStatus = ReplayStatus::Queued;
$newStatus = ReplayStatus::Processing;

if ($currentStatus->canTransitionTo($newStatus)) {
    $replay->status = $newStatus;
    $replay->save();
}

// Or enforce with exception
try {
    $replay->status = $currentStatus->transitionTo(ReplayStatus::Completed);
    $replay->save();
} catch (InvalidStatusTransitionException $e) {
    // Handle invalid transition
    logger()->error('Invalid replay status transition', [
        'from' => $currentStatus->value,
        'to' => 'completed',
        'error' => $e->getMessage(),
    ]);
}
```

**Create Exception Class:**
```php
// Create: src/Exceptions/InvalidStatusTransitionException.php

<?php declare(strict_types=1);

namespace Cline\Forrst\Exceptions;

use RuntimeException;

/**
 * Exception thrown when attempting an invalid replay status transition.
 */
final class InvalidStatusTransitionException extends RuntimeException
{
}
```

### 🟠 Major Issue #1: Ambiguous Distinction Between Completed and Processed

**Issue:** Both `Completed` and `Processed` are terminal states, but their distinction is unclear. The documentation states `Completed` means "executed without errors" while `Processed` means "fully executed and results available" - these seem redundant.

**Location:** Lines 42-48 (Completed), Lines 74-79 (Processed)

**Impact:**
- Confusion about which state to use
- Inconsistent state usage across the application
- Unclear when one state vs the other should be set
- Potential for logic bugs where code checks for one but not the other

**Solution:**

**Option 1: Clarify the distinction in documentation**
```php
/**
 * Replay operation completed successfully.
 *
 * Terminal state indicating successful execution. The replayed function
 * executed without errors and returned a valid response. The response
 * is cached and available for retrieval, but may not have been delivered
 * to the client yet (use Processed if delivery confirmation is needed).
 */
case Completed = 'completed';

/**
 * Replay operation has been processed and results delivered.
 *
 * Terminal state indicating the replay has been fully executed AND
 * the results have been successfully delivered to the requesting client.
 * This state confirms end-to-end completion including result delivery.
 * Only use this state if your system tracks result delivery separately
 * from execution completion.
 */
case Processed = 'processed';
```

**Option 2: Remove Processed if redundant**
```php
// If Processed truly duplicates Completed functionality, consider removing it
// and using Completed for all successful completions. Update isTerminal():

public function isTerminal(): bool
{
    return match ($this) {
        self::Completed, self::Failed, self::Expired, self::Cancelled => true,
        default => false,
    };
}
```

**Recommendation:** Clarify in documentation when each should be used, or remove `Processed` if it's truly redundant. Consult the Forrst protocol specification for guidance.

### 🟡 Minor Issue #1: Missing Helper Methods for Common State Checks

**Issue:** No helper methods for checking success/failure states, requiring verbose manual checks throughout the codebase.

**Location:** Entire file (missing functionality)

**Impact:** Code duplication, verbose conditional logic, reduced readability

**Solution:**
```php
// Add to ReplayStatus.php:

/**
 * Check if this status represents a successful outcome.
 *
 * Success states indicate the replay executed without errors and
 * produced a valid result. Includes both completed and processed
 * terminal states.
 *
 * @return bool True if the replay succeeded, false otherwise
 */
public function isSuccess(): bool
{
    return match ($this) {
        self::Completed, self::Processed => true,
        default => false,
    };
}

/**
 * Check if this status represents a failed outcome.
 *
 * Failed states indicate the replay encountered an error during
 * execution or was unable to complete for other reasons.
 *
 * @return bool True if the replay failed or was cancelled/expired
 */
public function isFailure(): bool
{
    return match ($this) {
        self::Failed, self::Cancelled, self::Expired => true,
        default => false,
    };
}

/**
 * Check if this status represents an active (non-terminal) state.
 *
 * Active states are replays that are still in progress or waiting
 * to be processed. These replays have not reached a final outcome.
 *
 * @return bool True if replay is active (queued or processing)
 */
public function isActive(): bool
{
    return !$this->isTerminal();
}

/**
 * Check if this status indicates the replay is currently executing.
 *
 * @return bool True if replay is being processed right now
 */
public function isProcessing(): bool
{
    return $this === self::Processing;
}

/**
 * Check if this status indicates the replay is waiting to execute.
 *
 * @return bool True if replay is queued but not yet started
 */
public function isQueued(): bool
{
    return $this === self::Queued;
}
```

**Usage:**
```php
// Instead of:
if ($replay->status === ReplayStatus::Completed || $replay->status === ReplayStatus::Processed) {
    // Handle success
}

// Use:
if ($replay->status->isSuccess()) {
    // Handle success
}

// Filter active replays
$activeReplays = $replays->filter(fn($r) => $r->status->isActive());
```

### 🟡 Minor Issue #2: Missing Status Metadata and Display Helpers

**Issue:** No methods to get human-readable labels, icons, or CSS classes for UI display.

**Location:** Entire file (missing functionality)

**Impact:** Inconsistent UI representation, duplicated display logic

**Solution:**
```php
// Add to ReplayStatus.php:

/**
 * Get a human-readable label for this status.
 *
 * Provides display-friendly status text for UI elements, notifications,
 * and user-facing messages.
 *
 * @return string Human-readable status label
 */
public function label(): string
{
    return match ($this) {
        self::Queued => 'Queued',
        self::Processing => 'Processing',
        self::Completed => 'Completed',
        self::Failed => 'Failed',
        self::Expired => 'Expired',
        self::Cancelled => 'Cancelled',
        self::Processed => 'Processed',
    };
}

/**
 * Get a detailed description of this status.
 *
 * Provides user-friendly explanation of what this status means,
 * useful for tooltips, help text, and status explanations.
 *
 * @return string Status description
 */
public function description(): string
{
    return match ($this) {
        self::Queued => 'Waiting in queue to be processed',
        self::Processing => 'Currently being executed',
        self::Completed => 'Successfully completed execution',
        self::Failed => 'Execution failed with an error',
        self::Expired => 'Expired before processing could complete',
        self::Cancelled => 'Cancelled by user request',
        self::Processed => 'Fully processed and results delivered',
    };
}

/**
 * Get an icon representing this status.
 *
 * @return string Icon/emoji for visual status indication
 */
public function icon(): string
{
    return match ($this) {
        self::Queued => '⏳',
        self::Processing => '⚙️',
        self::Completed => '✅',
        self::Failed => '❌',
        self::Expired => '⌛',
        self::Cancelled => '🚫',
        self::Processed => '✨',
    };
}

/**
 * Get CSS class name for this status.
 *
 * @return string CSS class for consistent styling
 */
public function cssClass(): string
{
    return match ($this) {
        self::Queued => 'status-queued',
        self::Processing => 'status-processing',
        self::Completed => 'status-success',
        self::Failed => 'status-error',
        self::Expired => 'status-expired',
        self::Cancelled => 'status-cancelled',
        self::Processed => 'status-processed',
    };
}

/**
 * Get color code for this status.
 *
 * Returns hex color codes for consistent visual representation
 * across charts, badges, and UI elements.
 *
 * @return string Hex color code (e.g., '#28a745')
 */
public function color(): string
{
    return match ($this) {
        self::Queued => '#6c757d',      // Gray
        self::Processing => '#007bff',   // Blue
        self::Completed => '#28a745',    // Green
        self::Failed => '#dc3545',       // Red
        self::Expired => '#fd7e14',      // Orange
        self::Cancelled => '#6c757d',    // Gray
        self::Processed => '#20c997',    // Teal
    };
}
```

---

## Security Vulnerabilities

### 🟡 Security Concern: State Transition Authorization

**Issue:** Without validation in the application layer, unauthorized users could manipulate replay statuses, potentially marking failed replays as completed or cancelling others' replays.

**Location:** Application usage (not in this file)

**Impact:** Data integrity violations, audit trail corruption, potential abuse

**Recommendation:**
```php
// In the application layer (e.g., ReplayController or ReplayService):

/**
 * Authorize and execute a replay status transition.
 *
 * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
 * @throws \Cline\Forrst\Exceptions\InvalidStatusTransitionException
 */
public function transitionReplayStatus(
    Replay $replay,
    ReplayStatus $newStatus,
    User $user
): void {
    // Only allow certain transitions for non-admin users
    if (!$user->isAdmin()) {
        $allowedTransitions = [ReplayStatus::Cancelled];

        if (!in_array($newStatus, $allowedTransitions, true)) {
            throw new AccessDeniedHttpException(
                'You do not have permission to change replay status to ' . $newStatus->value
            );
        }

        // Users can only cancel their own replays
        if ($newStatus === ReplayStatus::Cancelled && $replay->user_id !== $user->id) {
            throw new AccessDeniedHttpException(
                'You can only cancel your own replays'
            );
        }
    }

    // Validate the transition
    $validatedStatus = $replay->status->transitionTo($newStatus);

    // Update with audit trail
    $replay->status = $validatedStatus;
    $replay->status_changed_at = now();
    $replay->status_changed_by = $user->id;
    $replay->save();

    // Log the transition
    logger()->info('Replay status changed', [
        'replay_id' => $replay->id,
        'from' => $replay->getOriginal('status'),
        'to' => $newStatus->value,
        'user_id' => $user->id,
    ]);
}
```

---

## Performance Concerns

### Excellent Performance Profile

**No performance issues.** The implementation is optimal:

1. **Match Expression:** O(1) complexity for `isTerminal()`
2. **Enum Singleton:** Minimal memory overhead (~32-64 bytes per case)
3. **No I/O Operations:** Pure domain logic only

**Proposed Additions Performance:**
- `canTransitionTo()`: O(1) - single match + array membership check
- `isSuccess()`, `isFailure()`: O(1) - single match expression
- `getValidTransitions()`: O(1) - returns fixed-size array
- Display methods: O(1) - all single match expressions

---

## Maintainability Assessment

### Good Maintainability - Score: 8.0/10

**Strengths:**
1. Clear, descriptive state names
2. Excellent PHPDoc documentation for each state
3. Logical state progression
4. Terminal state handling is explicit

**Weaknesses:**
1. No transition validation makes extending risky
2. Ambiguous distinction between Completed and Processed
3. Missing helper methods increases boilerplate in consuming code
4. No state transition documentation or diagram

**Improvement Recommendations:**

1. **Add State Transition Diagram to Documentation:**
```php
/**
 * Lifecycle status values for replay operations in the Forrst replay extension.
 *
 * Tracks the current state of a replay operation from initial queuing through
 * terminal states like completion or failure. Status transitions follow a defined
 * lifecycle where certain states are terminal and cannot transition further.
 *
 * State Transition Flow:
 *
 *     ┌─────────┐
 *     │ Queued  │────┐
 *     └────┬────┘    │
 *          │         │
 *          ▼         │
 *    ┌────────────┐  │
 *    │ Processing │  │
 *    └─────┬──────┘  │
 *          │         │
 *    ┌─────┴────────────────┬──────────┬────────────┐
 *    ▼          ▼           ▼          ▼            ▼
 * ┌─────────┐ ┌──────┐ ┌─────────┐ ┌──────────┐ ┌───────────┐
 * │Completed│ │Failed│ │Cancelled│ │ Expired  │ │ Processed │
 * └─────────┘ └──────┘ └─────────┘ └──────────┘ └───────────┘
 *  (Terminal) (Terminal) (Terminal)  (Terminal)   (Terminal)
 *
 * Transition Rules:
 * - Queued can transition to: Processing, Cancelled, Expired
 * - Processing can transition to: Completed, Failed, Cancelled, Expired, Processed
 * - Terminal states (Completed, Failed, Cancelled, Expired, Processed) cannot transition
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/replay
 */
```

2. **Create Comprehensive Test Suite:**
```php
// tests/Unit/Enums/ReplayStatusTest.php

namespace Tests\Unit\Enums;

use Cline\Forrst\Enums\ReplayStatus;
use Cline\Forrst\Exceptions\InvalidStatusTransitionException;
use PHPUnit\Framework\TestCase;

final class ReplayStatusTest extends TestCase
{
    /** @test */
    public function it_identifies_terminal_states_correctly(): void
    {
        $terminalStates = [
            ReplayStatus::Completed,
            ReplayStatus::Failed,
            ReplayStatus::Expired,
            ReplayStatus::Cancelled,
            ReplayStatus::Processed,
        ];

        foreach ($terminalStates as $status) {
            $this->assertTrue(
                $status->isTerminal(),
                sprintf('%s should be terminal', $status->value)
            );
        }
    }

    /** @test */
    public function it_identifies_non_terminal_states_correctly(): void
    {
        $this->assertFalse(ReplayStatus::Queued->isTerminal());
        $this->assertFalse(ReplayStatus::Processing->isTerminal());
    }

    /** @test */
    public function queued_can_transition_to_processing(): void
    {
        $this->assertTrue(
            ReplayStatus::Queued->canTransitionTo(ReplayStatus::Processing)
        );
    }

    /** @test */
    public function queued_can_transition_to_cancelled(): void
    {
        $this->assertTrue(
            ReplayStatus::Queued->canTransitionTo(ReplayStatus::Cancelled)
        );
    }

    /** @test */
    public function queued_cannot_transition_to_completed(): void
    {
        $this->assertFalse(
            ReplayStatus::Queued->canTransitionTo(ReplayStatus::Completed)
        );
    }

    /** @test */
    public function processing_can_transition_to_completed(): void
    {
        $this->assertTrue(
            ReplayStatus::Processing->canTransitionTo(ReplayStatus::Completed)
        );
    }

    /** @test */
    public function processing_can_transition_to_failed(): void
    {
        $this->assertTrue(
            ReplayStatus::Processing->canTransitionTo(ReplayStatus::Failed)
        );
    }

    /** @test */
    public function terminal_states_cannot_transition(): void
    {
        $terminalStates = [
            ReplayStatus::Completed,
            ReplayStatus::Failed,
            ReplayStatus::Expired,
            ReplayStatus::Cancelled,
            ReplayStatus::Processed,
        ];

        foreach ($terminalStates as $from) {
            foreach (ReplayStatus::cases() as $to) {
                $this->assertFalse(
                    $from->canTransitionTo($to),
                    sprintf('%s (terminal) should not transition to %s', $from->value, $to->value)
                );
            }
        }
    }

    /** @test */
    public function transition_to_throws_exception_for_invalid_transitions(): void
    {
        $this->expectException(InvalidStatusTransitionException::class);

        ReplayStatus::Completed->transitionTo(ReplayStatus::Processing);
    }

    /** @test */
    public function transition_to_returns_new_status_for_valid_transitions(): void
    {
        $newStatus = ReplayStatus::Queued->transitionTo(ReplayStatus::Processing);

        $this->assertSame(ReplayStatus::Processing, $newStatus);
    }

    /** @test */
    public function get_valid_transitions_returns_empty_for_terminal_states(): void
    {
        $this->assertEmpty(ReplayStatus::Completed->getValidTransitions());
        $this->assertEmpty(ReplayStatus::Failed->getValidTransitions());
    }

    /** @test */
    public function get_valid_transitions_returns_correct_options_for_queued(): void
    {
        $transitions = ReplayStatus::Queued->getValidTransitions();

        $this->assertContains(ReplayStatus::Processing, $transitions);
        $this->assertContains(ReplayStatus::Cancelled, $transitions);
        $this->assertContains(ReplayStatus::Expired, $transitions);
    }

    /** @test */
    public function is_success_identifies_successful_states(): void
    {
        $this->assertTrue(ReplayStatus::Completed->isSuccess());
        $this->assertTrue(ReplayStatus::Processed->isSuccess());
        $this->assertFalse(ReplayStatus::Failed->isSuccess());
        $this->assertFalse(ReplayStatus::Cancelled->isSuccess());
    }

    /** @test */
    public function is_failure_identifies_failed_states(): void
    {
        $this->assertTrue(ReplayStatus::Failed->isFailure());
        $this->assertTrue(ReplayStatus::Cancelled->isFailure());
        $this->assertTrue(ReplayStatus::Expired->isFailure());
        $this->assertFalse(ReplayStatus::Completed->isFailure());
    }

    /** @test */
    public function is_active_identifies_non_terminal_states(): void
    {
        $this->assertTrue(ReplayStatus::Queued->isActive());
        $this->assertTrue(ReplayStatus::Processing->isActive());
        $this->assertFalse(ReplayStatus::Completed->isActive());
    }
}
```

---

## Additional Recommendations

### 1. Add Timestamp Tracking Support

```php
// Migration for replays table should include:
Schema::table('replays', function (Blueprint $table) {
    $table->timestamp('queued_at')->nullable();
    $table->timestamp('processing_started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
});

// In Replay model, add status transition observer:
class Replay extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::updating(function (Replay $replay) {
            if ($replay->isDirty('status')) {
                $replay->recordStatusTransition(
                    $replay->getOriginal('status'),
                    $replay->status
                );
            }
        });
    }

    protected function recordStatusTransition(?ReplayStatus $from, ReplayStatus $to): void
    {
        match ($to) {
            ReplayStatus::Queued => $this->queued_at = now(),
            ReplayStatus::Processing => $this->processing_started_at = now(),
            ReplayStatus::Completed,
            ReplayStatus::Failed,
            ReplayStatus::Cancelled,
            ReplayStatus::Expired,
            ReplayStatus::Processed => $this->completed_at = now(),
        };
    }
}
```

### 2. Add SLA and Duration Helpers

```php
// Add to ReplayStatus.php:

/**
 * Get the expected maximum duration for this status.
 *
 * Provides SLA targets for monitoring and alerting. Replays exceeding
 * these durations may indicate performance issues or stuck operations.
 *
 * @return null|int Maximum expected seconds in this status, null if unlimited
 */
public function getExpectedDuration(): ?int
{
    return match ($this) {
        self::Queued => 300,      // 5 minutes max in queue
        self::Processing => 3600,  // 1 hour max processing time
        default => null,          // Terminal states have no duration limit
    };
}
```

---

## Conclusion

The `ReplayStatus` enum provides a solid foundation for replay lifecycle management with clear state definitions and terminal state handling. However, it critically lacks state transition validation, which is essential for maintaining data integrity in a state machine.

**Final Score: 8.0/10**

**Strengths:**
- Clear, well-documented states
- Explicit terminal state handling
- Logical lifecycle progression
- Comprehensive terminal state coverage

**Critical Improvements Needed:**
1. **State Transition Validation** (Critical): Add `canTransitionTo()`, `transitionTo()`, `getValidTransitions()`
2. **Clarify Completed vs Processed** (Major): Document distinction or remove redundancy
3. **Helper Methods** (Minor): Add `isSuccess()`, `isFailure()`, `isActive()`, display helpers
4. **Authorization** (Security): Implement application-layer status transition authorization

**Recommended Next Steps:**
1. Implement state transition validation (Critical Issue #1) - **Priority: CRITICAL**
2. Clarify or resolve Completed/Processed ambiguity (Major Issue #1) - **Priority: HIGH**
3. Add helper methods for state checking (Minor Issue #1) - **Priority: MEDIUM**
4. Add display/UI helper methods (Minor Issue #2) - **Priority: LOW**
5. Implement authorization layer for status transitions - **Priority: HIGH**
6. Create comprehensive test suite - **Priority: HIGH**
7. Add state transition diagram to documentation - **Priority: MEDIUM**

**Implementation Priority:** The state transition validation is CRITICAL and should be implemented immediately. Without it, the application can easily get into invalid states, corrupting data and breaking business logic assumptions.

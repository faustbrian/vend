<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Enums;

/**
 * Lifecycle status values for replay operations in the Forrst replay extension.
 *
 * Tracks the current state of a replay operation from initial queuing through
 * terminal states like completion or failure. Status transitions follow a defined
 * lifecycle where certain states are terminal and cannot transition further.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/replay
 */
enum ReplayStatus: string
{
    /**
     * Replay operation is queued and waiting to be processed.
     *
     * Initial state for newly created replays. The operation is scheduled
     * for execution but has not yet begun processing.
     */
    case Queued = 'queued';

    /**
     * Replay operation is currently being processed.
     *
     * Active state indicating the replay is executing. The operation has
     * started but not yet reached a terminal state.
     */
    case Processing = 'processing';

    /**
     * Replay operation completed successfully.
     *
     * Terminal state indicating successful execution. The replayed function
     * executed without errors and returned a valid response. The response
     * is cached and available for retrieval, but may not have been delivered
     * to the client yet (use Processed if delivery confirmation is needed).
     *
     * Use this state when the replay execution finished successfully but
     * you don't need to track result delivery separately.
     */
    case Completed = 'completed';

    /**
     * Replay operation failed during execution.
     *
     * Terminal state indicating an error occurred during replay execution.
     * The failure reason and details are available in the replay metadata.
     */
    case Failed = 'failed';

    /**
     * Replay operation expired before processing could complete.
     *
     * Terminal state indicating the replay exceeded its time-to-live or
     * retention period. Expired replays cannot be processed or retrieved.
     */
    case Expired = 'expired';

    /**
     * Replay operation was explicitly cancelled.
     *
     * Terminal state indicating the replay was cancelled by client request
     * before completion. Cancelled replays will not be processed further.
     */
    case Cancelled = 'cancelled';

    /**
     * Replay operation has been processed and results delivered.
     *
     * Terminal state indicating the replay has been fully executed AND
     * the results have been successfully delivered to the requesting client.
     * This state confirms end-to-end completion including result delivery.
     *
     * Only use this state if your system tracks result delivery separately
     * from execution completion. If you don't need to distinguish between
     * execution and delivery, use Completed instead.
     */
    case Processed = 'processed';

    /**
     * Check if this status represents a terminal state.
     *
     * Terminal states are final states where no further status transitions
     * are possible. Once a replay reaches a terminal state, it cannot be
     * reprocessed, resumed, or modified. This is used to determine if a
     * replay operation has concluded its lifecycle.
     *
     * @return bool True if this is a terminal state that prevents further transitions
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Expired, self::Cancelled, self::Processed => true,
            default => false,
        };
    }

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
     * - Processing → Completed, Failed, Cancelled, Expired, Processed
     * - Terminal states → No transitions allowed
     *
     * @param self $newStatus The proposed new status
     *
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
     *
     * @throws \Cline\Forrst\Exceptions\InvalidStatusTransitionException
     *
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
}

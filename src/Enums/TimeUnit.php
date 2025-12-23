<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Enums;

/**
 * Time unit values for duration and time-to-live specifications.
 *
 * Provides standardized time units used throughout the Forrst protocol for
 * duration values, timeouts, cache TTLs, and replay retention periods.
 * Supports conversion to seconds for consistent internal time calculations.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/protocol
 */
enum TimeUnit: string
{
    /**
     * Second time unit for sub-minute granularity.
     *
     * Used for short-duration timeouts, request deadlines, and
     * high-precision timing requirements.
     */
    case Second = 'second';

    /**
     * Minute time unit for moderate-duration specifications.
     *
     * Common for request timeouts, short-term cache TTLs, and
     * function execution limits.
     */
    case Minute = 'minute';

    /**
     * Hour time unit for long-running operation specifications.
     *
     * Used for extended timeouts, multi-hour cache retention,
     * and long-running async operations.
     */
    case Hour = 'hour';

    /**
     * Day time unit for extended retention periods.
     *
     * Typically used for replay retention policies, long-term
     * cache storage, and multi-day operation windows.
     */
    case Day = 'day';

    /**
     * Convert a duration value in this unit to seconds.
     *
     * Normalizes time values to seconds for consistent internal processing,
     * comparison, and storage. This enables uniform handling of durations
     * regardless of the original unit specification.
     *
     * @param int $value Duration value in the current time unit (must be non-negative)
     *
     * @return int Duration in seconds (1 minute = 60s, 1 hour = 3600s, 1 day = 86400s)
     *
     * @throws \InvalidArgumentException If value is negative or would cause integer overflow
     */
    public function toSeconds(int $value): int
    {
        // Validate non-negative constraint
        if ($value < 0) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Duration value must be non-negative, got %d %s',
                    $value,
                    $this->value
                )
            );
        }

        // Check for potential overflow before multiplication
        $multiplier = match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour => 3_600,
            self::Day => 86_400,
        };

        // PHP_INT_MAX / multiplier gives the maximum safe value before overflow
        $maxSafeValue = (int) floor(PHP_INT_MAX / $multiplier);

        if ($value > $maxSafeValue) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Duration value %d %s would cause integer overflow (max: %d)',
                    $value,
                    $this->value,
                    $maxSafeValue
                )
            );
        }

        return $value * $multiplier;
    }
}

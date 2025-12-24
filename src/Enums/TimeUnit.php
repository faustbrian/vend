<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Enums;

use Cline\Forrst\Exceptions\NegativeValueException;
use Cline\Forrst\Exceptions\OverflowException;
use InvalidArgumentException;

use const PHP_INT_MAX;

use function floor;
use function sprintf;

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
     * Find the best time unit to represent a duration in seconds.
     *
     * Selects the largest time unit where the duration is >= 1.0,
     * making durations more human-readable (e.g., "2 hours" instead of "7200 seconds").
     *
     * @param int $seconds Duration in seconds (must be non-negative)
     *
     * @throws InvalidArgumentException If seconds is negative
     * @return self                     The most appropriate time unit for this duration
     */
    public static function bestFit(int $seconds): self
    {
        if ($seconds < 0) {
            throw NegativeValueException::forField(sprintf('Seconds (%d)', $seconds));
        }

        return match (true) {
            $seconds >= 86_400 => self::Day,
            $seconds >= 3_600 => self::Hour,
            $seconds >= 60 => self::Minute,
            default => self::Second,
        };
    }

    /**
     * Convert seconds to the best-fit time unit and value.
     *
     * Combines bestFit() and fromSeconds() to automatically select the most
     * appropriate time unit and convert the duration. Useful for displaying
     * durations in human-readable format.
     *
     * @param int $seconds Duration in seconds (must be non-negative)
     *
     * @throws InvalidArgumentException        If seconds is negative
     * @return array{value: float, unit: self} Duration and best-fit time unit
     */
    public static function fromSecondsAuto(int $seconds): array
    {
        $unit = self::bestFit($seconds);

        return [
            'value' => $unit->fromSeconds($seconds),
            'unit' => $unit,
        ];
    }

    /**
     * Convert a duration value in this unit to seconds.
     *
     * Normalizes time values to seconds for consistent internal processing,
     * comparison, and storage. This enables uniform handling of durations
     * regardless of the original unit specification.
     *
     * @param int $value Duration value in the current time unit (must be non-negative)
     *
     * @throws InvalidArgumentException If value is negative or would cause integer overflow
     * @return int                      Duration in seconds (1 minute = 60s, 1 hour = 3600s, 1 day = 86400s)
     */
    public function toSeconds(int $value): int
    {
        // Validate non-negative constraint
        if ($value < 0) {
            throw NegativeValueException::forField(sprintf('Duration value (%d %s)', $value, $this->value));
        }

        // Check for potential overflow before multiplication
        $multiplier = match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour => 3_600,
            self::Day => 86_400,
        };

        // PHP_INT_MAX / multiplier gives the maximum safe value before overflow
        $maxSafeValue = (int) (PHP_INT_MAX / $multiplier);

        if ($value > $maxSafeValue) {
            throw OverflowException::forOperation(sprintf('toSeconds conversion (%d %s)', $value, $this->value));
        }

        return $value * $multiplier;
    }

    /**
     * Convert seconds to a value in this time unit.
     *
     * Performs the inverse operation of toSeconds(), converting a duration
     * in seconds back to this time unit. The result may be fractional and
     * is returned as a float for precision.
     *
     * @param int $seconds Duration in seconds (must be non-negative)
     *
     * @throws InvalidArgumentException If seconds is negative
     * @return float                    Duration value in this time unit (may be fractional)
     */
    public function fromSeconds(int $seconds): float
    {
        if ($seconds < 0) {
            throw NegativeValueException::forField(sprintf('Seconds (%d)', $seconds));
        }

        return match ($this) {
            self::Second => (float) $seconds,
            self::Minute => $seconds / 60.0,
            self::Hour => $seconds / 3_600.0,
            self::Day => $seconds / 86_400.0,
        };
    }
}

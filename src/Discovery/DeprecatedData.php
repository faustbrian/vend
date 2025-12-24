<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use Carbon\CarbonImmutable;
use Cline\Forrst\Exceptions\HtmlNotAllowedException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\MissingRequiredFieldException;
use Cline\Forrst\Exceptions\WhitespaceOnlyException;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

use function array_any;
use function mb_strlen;
use function mb_trim;
use function preg_match;
use function sprintf;
use function strip_tags;

/**
 * Deprecation metadata for API elements being phased out.
 *
 * Indicates that a function, parameter, or other API element is deprecated and
 * should not be used in new code. Provides context about why the element is
 * deprecated and when it will be removed. The presence of this object alone
 * signifies deprecation status.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 * @see https://docs.cline.sh/forrst/extensions/deprecation
 */
final class DeprecatedData extends Data
{
    /**
     * The sunset date when this element will be removed.
     */
    public readonly ?DateTimeImmutable $sunset;

    /**
     * Create a new deprecation information instance.
     *
     * @param  null|string                       $reason A human-readable explanation of why this element is deprecated.
     *                                                   Typically includes guidance on what to use instead, such as
     *                                                   "Use createUser() instead" or "Replaced by v2 authentication".
     *                                                   Helps developers migrate to supported alternatives.
     * @param  null|DateTimeImmutable|string     $sunset The date when this deprecated element will be removed from the API.
     *                                                   Can be a DateTimeImmutable object or ISO 8601 date string.
     *                                                   Provides a timeline for migration planning. Null indicates no specific
     *                                                   removal date has been set, though the element should still not be used in new code.
     * @throws InvalidArgumentException if sunset date is in the past or validation fails
     */
    public function __construct(
        public readonly ?string $reason = null,
        DateTimeImmutable|string|null $sunset = null,
    ) {
        // Convert string sunset to DateTimeImmutable
        if (is_string($sunset)) {
            try {
                $sunset = new DateTimeImmutable($sunset);
            } catch (Exception) {
                throw InvalidFieldValueException::forField(
                    'sunset',
                    sprintf('Invalid date format "%s". Expected ISO 8601 format (e.g., "2025-12-31")', $sunset),
                );
            }
        }

        $this->sunset = $sunset;
        $this->validateDeprecation();
    }

    /**
     * Create deprecation data with string sunset date.
     *
     * @param  null|string              $reason     Deprecation reason
     * @param  null|string              $sunsetDate ISO 8601 date string (e.g., "2025-12-31")
     * @throws InvalidArgumentException if date format is invalid
     */
    public static function create(?string $reason = null, ?string $sunsetDate = null): self
    {
        $sunset = null;

        if ($sunsetDate !== null) {
            try {
                $sunset = new DateTimeImmutable($sunsetDate);
            } catch (Exception) {
                throw InvalidFieldValueException::forField(
                    'sunset',
                    sprintf('Invalid date format "%s". Expected ISO 8601 format (e.g., "2025-12-31")', $sunsetDate),
                );
            }
        }

        return new self(reason: $reason, sunset: $sunset);
    }

    /**
     * Get sunset date as ISO 8601 string.
     */
    public function getSunsetString(): ?string
    {
        return $this->sunset?->format('Y-m-d');
    }

    /**
     * Check if the sunset date has passed.
     */
    public function hasSunsetPassed(): bool
    {
        if (!$this->sunset instanceof DateTimeImmutable) {
            return false;
        }

        return $this->sunset < CarbonImmutable::now();
    }

    /**
     * Get days until sunset.
     *
     * @return null|int Days remaining until sunset, or null if no sunset set
     */
    public function getDaysUntilSunset(): ?int
    {
        if (!$this->sunset instanceof DateTimeImmutable) {
            return null;
        }

        $now = CarbonImmutable::now();
        $interval = $now->diff($this->sunset);

        return (int) $interval->format('%r%a'); // Positive for future, negative for past
    }

    /**
     * Check if sunset is approaching (within 90 days).
     *
     * @param int $days Number of days to consider as "approaching" (default 90)
     */
    public function isSunsetApproaching(int $days = 90): bool
    {
        $daysUntil = $this->getDaysUntilSunset();

        if ($daysUntil === null) {
            return false;
        }

        return $daysUntil > 0 && $daysUntil <= $days;
    }

    /**
     * Check if the reason includes alternative suggestions.
     */
    public function hasAlternativeSuggestion(): bool
    {
        if ($this->reason === null) {
            return false;
        }

        $patterns = [
            '/use\s+\w+\s+instead/i',
            '/replaced\s+by/i',
            '/migrate\s+to/i',
            '/see\s+\w+/i',
            '/instead\s+of/i',
        ];

        return array_any($patterns, fn ($pattern): int|false => preg_match($pattern, $this->reason));
    }

    /**
     * Get a deprecation warning message.
     *
     * @param string $elementName Name of the deprecated element
     */
    public function getWarningMessage(string $elementName): string
    {
        $message = sprintf('DEPRECATED: %s is deprecated', $elementName);

        if ($this->reason !== null) {
            $message .= '. '.$this->reason;
        }

        if ($this->sunset instanceof DateTimeImmutable) {
            $message .= sprintf(
                ' and will be removed on %s (%d days remaining)',
                $this->getSunsetString(),
                $this->getDaysUntilSunset(),
            );
        }

        return $message;
    }

    /**
     * Validate deprecation data.
     *
     * @throws InvalidArgumentException
     */
    private function validateDeprecation(): void
    {
        if ($this->reason !== null) {
            $this->validateReason();
        }

        if ($this->sunset instanceof DateTimeImmutable) {
            $this->validateSunset();
        }

        $this->validateAtLeastOneFieldPresent();
    }

    /**
     * Validate the reason field.
     *
     * @throws InvalidArgumentException
     */
    private function validateReason(): void
    {
        $trimmedReason = mb_trim((string) $this->reason);

        if ($trimmedReason === '') {
            throw WhitespaceOnlyException::forField('reason');
        }

        if (mb_strlen($trimmedReason) < 10) {
            throw InvalidFieldValueException::forField(
                'reason',
                'Deprecation reason must be at least 10 characters to provide meaningful context',
            );
        }

        if (mb_strlen($trimmedReason) > 1_000) {
            throw InvalidFieldValueException::forField(
                'reason',
                sprintf('Deprecation reason cannot exceed 1000 characters, got %d', mb_strlen($trimmedReason)),
            );
        }

        // Prevent HTML injection
        if ($trimmedReason !== strip_tags($trimmedReason)) {
            throw HtmlNotAllowedException::forField('reason');
        }

        // Check for suspicious patterns
        $suspiciousPatterns = [
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i', // Event handlers like onclick=
            '/<iframe/i',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $trimmedReason)) {
                throw InvalidFieldValueException::forField('reason', 'Contains potentially malicious content');
            }
        }
    }

    /**
     * Validate the sunset field.
     *
     * @throws InvalidArgumentException
     */
    private function validateSunset(): void
    {
        $now = CarbonImmutable::now();

        if ($this->sunset < $now) {
            throw InvalidFieldValueException::forField(
                'sunset',
                sprintf(
                    'Sunset date "%s" is in the past. Deprecated elements with past sunset dates should be removed.',
                    $this->sunset->format('Y-m-d'),
                ),
            );
        }
    }

    /**
     * Validate that at least one field is present.
     *
     * @throws InvalidArgumentException
     */
    private function validateAtLeastOneFieldPresent(): void
    {
        if ($this->reason === null && !$this->sunset instanceof DateTimeImmutable) {
            throw MissingRequiredFieldException::forField('reason or sunset');
        }
    }
}

# Code Review: DeprecatedData.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Discovery/DeprecatedData.php`
**Purpose:** Defines a Data Transfer Object for deprecation metadata in API elements. Indicates that functions, parameters, or other API components are deprecated and provides migration guidance including reasons and removal timelines.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) ✅
**Rating: Excellent**

The class has a single, focused responsibility: representing deprecation metadata. It contains only deprecation-related information without mixing concerns.

### Open/Closed Principle (OCP) ✅
**Rating: Good**

Marked as `final`, which is appropriate for this simple DTO. Extension through composition is possible when needed.

### Liskov Substitution Principle (LSP) ✅
**Rating: Good**

Properly extends `Spatie\LaravelData\Data` maintaining behavioral compatibility.

### Interface Segregation Principle (ISP) ✅
**Rating: Good**

All properties are cohesive and relate to deprecation information.

### Dependency Inversion Principle (DIP) ✅
**Rating: Good**

Minimal dependencies, only on the base `Data` class.

---

## Code Quality Issues

### 🟠 Major Issue: Missing Date Validation for Sunset Field
**Location:** Line 42

**Issue:** The `$sunset` field accepts any string without validating that it's a proper ISO 8601 date format. Invalid dates can be stored, leading to parsing errors and confusion about deprecation timelines.

**Impact:**
- Invalid sunset dates stored in discovery documents
- Parser errors when clients try to parse sunset dates
- Ambiguous deprecation timelines
- Inability to programmatically determine if deprecation deadline has passed
- Poor developer experience

**Solution:**
Add validation and use a proper date object:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Deprecation metadata for API elements being phased out.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 * @see https://docs.cline.sh/forrst/extensions/deprecation
 */
final class DeprecatedData extends Data
{
    /**
     * Create a new deprecation information instance.
     *
     * @param null|string $reason A human-readable explanation of why this element is deprecated
     * @param null|\DateTimeImmutable $sunset The date when this deprecated element will be removed
     * @throws \InvalidArgumentException if sunset date is in the past
     */
    public function __construct(
        public readonly ?string $reason = null,
        public readonly ?\DateTimeImmutable $sunset = null,
    ) {
        $this->validate();
    }

    /**
     * Create deprecation data with string sunset date.
     *
     * @param null|string $reason Deprecation reason
     * @param null|string $sunsetDate ISO 8601 date string (e.g., "2025-12-31")
     * @return self
     * @throws \InvalidArgumentException if date format is invalid
     */
    public static function create(?string $reason = null, ?string $sunsetDate = null): self
    {
        $sunset = null;

        if ($sunsetDate !== null) {
            try {
                $sunset = new \DateTimeImmutable($sunsetDate);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid sunset date format "%s". Expected ISO 8601 format (e.g., "2025-12-31")', $sunsetDate),
                    0,
                    $e
                );
            }
        }

        return new self(reason: $reason, sunset: $sunset);
    }

    /**
     * Validate deprecation data.
     *
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->reason !== null) {
            $this->validateReason();
        }

        if ($this->sunset !== null) {
            $this->validateSunset();
        }

        $this->validateAtLeastOneFieldPresent();
    }

    /**
     * Validate the reason field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateReason(): void
    {
        $trimmedReason = trim($this->reason);

        if ($trimmedReason === '') {
            throw new \InvalidArgumentException('Deprecation reason cannot be empty or whitespace only');
        }

        if (mb_strlen($trimmedReason) < 10) {
            throw new \InvalidArgumentException(
                'Deprecation reason must be at least 10 characters to provide meaningful context'
            );
        }

        if (mb_strlen($trimmedReason) > 1000) {
            throw new \InvalidArgumentException(
                sprintf('Deprecation reason cannot exceed 1000 characters, got %d', mb_strlen($trimmedReason))
            );
        }
    }

    /**
     * Validate the sunset field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateSunset(): void
    {
        $now = new \DateTimeImmutable();

        if ($this->sunset < $now) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Sunset date "%s" is in the past. Deprecated elements with past sunset dates should be removed.',
                    $this->sunset->format('Y-m-d')
                )
            );
        }

        // Warn if sunset is too far in the future (more than 5 years)
        $fiveYearsFromNow = $now->modify('+5 years');

        if ($this->sunset > $fiveYearsFromNow) {
            // This could be a warning in logs rather than exception
            // throw new \InvalidArgumentException('Sunset date is more than 5 years in the future');
        }
    }

    /**
     * Validate that at least one field is present.
     *
     * @throws \InvalidArgumentException
     */
    private function validateAtLeastOneFieldPresent(): void
    {
        if ($this->reason === null && $this->sunset === null) {
            throw new \InvalidArgumentException(
                'DeprecatedData requires at least one field (reason or sunset) to be provided'
            );
        }
    }

    /**
     * Get sunset date as ISO 8601 string.
     *
     * @return null|string
     */
    public function getSunsetString(): ?string
    {
        return $this->sunset?->format('Y-m-d');
    }

    /**
     * Check if the sunset date has passed.
     *
     * @return bool
     */
    public function hasSunsetPassed(): bool
    {
        if ($this->sunset === null) {
            return false;
        }

        return $this->sunset < new \DateTimeImmutable();
    }

    /**
     * Get days until sunset.
     *
     * @return null|int Days remaining until sunset, or null if no sunset set
     */
    public function getDaysUntilSunset(): ?int
    {
        if ($this->sunset === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $interval = $now->diff($this->sunset);

        return (int) $interval->format('%r%a'); // Positive for future, negative for past
    }

    /**
     * Check if sunset is approaching (within 90 days).
     *
     * @param int $days Number of days to consider as "approaching" (default 90)
     * @return bool
     */
    public function isSunsetApproaching(int $days = 90): bool
    {
        $daysUntil = $this->getDaysUntilSunset();

        if ($daysUntil === null) {
            return false;
        }

        return $daysUntil > 0 && $daysUntil <= $days;
    }
}
```

### 🟡 Minor Issue: No Guidance Enforcement in Reason Field
**Location:** Line 41

**Issue:** The PHPDoc mentions that reasons should include guidance on alternatives (e.g., "Use createUser() instead"), but there's no validation to ensure this guidance is provided.

**Impact:**
- Deprecation notices without migration paths frustrate developers
- Increased support burden answering "what should I use instead?"
- Slower adoption of replacement APIs

**Solution:**
While enforcing specific language patterns is difficult, we can encourage better practices:

```php
/**
 * Check if the reason includes alternative suggestions.
 *
 * @return bool
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

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $this->reason)) {
            return true;
        }
    }

    return false;
}

/**
 * Get a deprecation warning message.
 *
 * @param string $elementName Name of the deprecated element
 * @return string
 */
public function getWarningMessage(string $elementName): string
{
    $message = "DEPRECATED: {$elementName} is deprecated";

    if ($this->reason !== null) {
        $message .= ". {$this->reason}";
    }

    if ($this->sunset !== null) {
        $message .= sprintf(
            ' and will be removed on %s (%d days remaining)',
            $this->getSunsetString(),
            $this->getDaysUntilSunset()
        );
    }

    return $message;
}
```

### 🟡 Minor Issue: Missing Standard Deprecation Levels
**Location:** Class-level

**Issue:** No concept of deprecation severity or phases (e.g., "soft deprecated" vs "hard deprecated" vs "removed").

**Impact:**
- No way to differentiate between gentle warnings and urgent migrations
- Cannot implement phased deprecation strategies
- Difficulty prioritizing migration work

**Solution:**
Consider adding a severity level:

```php
// Create new enum: src/Discovery/DeprecationLevel.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

/**
 * Deprecation severity levels.
 */
enum DeprecationLevel: string
{
    /**
     * Soft deprecation: Feature still fully supported but discouraged for new code.
     */
    case SOFT = 'soft';

    /**
     * Standard deprecation: Feature will be removed in a future version.
     */
    case STANDARD = 'standard';

    /**
     * Hard deprecation: Feature will be removed soon, migrate immediately.
     */
    case HARD = 'hard';

    /**
     * Security deprecation: Feature has security issues and must be replaced.
     */
    case SECURITY = 'security';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SOFT => 'Soft Deprecated',
            self::STANDARD => 'Deprecated',
            self::HARD => 'Hard Deprecated (Removal Imminent)',
            self::SECURITY => 'Deprecated (Security Risk)',
        };
    }

    /**
     * Check if this is urgent.
     */
    public function isUrgent(): bool
    {
        return $this === self::HARD || $this === self::SECURITY;
    }
}
```

Then update DeprecatedData:

```php
public function __construct(
    public readonly ?string $reason = null,
    public readonly ?\DateTimeImmutable $sunset = null,
    public readonly DeprecationLevel $level = DeprecationLevel::STANDARD,
) {
    $this->validate();
}
```

### 🔵 Suggestion: Add Migration Helper Methods
**Location:** Class-level

**Issue:** No utility methods to help developers understand and act on deprecation information.

**Impact:** Reduced developer experience when encountering deprecated APIs.

**Solution:**
Add helper methods (shown in the validation solution above):
- `hasSunsetPassed()` - Check if removal date has passed
- `getDaysUntilSunset()` - Get remaining time
- `isSunsetApproaching()` - Check if sunset is near
- `getWarningMessage()` - Format deprecation warning
- `hasAlternativeSuggestion()` - Verify migration guidance is present

---

## Security Vulnerabilities

### 🟡 Minor Security Concern: Injection Risk in Reason Field
**Location:** Line 41

**Issue:** The `$reason` field accepts arbitrary text that could contain malicious content if rendered without escaping.

**Impact:**
- Cross-Site Scripting (XSS) if rendered in HTML without escaping
- Log injection if written to log files without sanitization
- Documentation defacement

**Solution:**
Validate reason content (shown above in validation code):

```php
private function validateReason(): void
{
    $trimmedReason = trim($this->reason);

    if ($trimmedReason === '') {
        throw new \InvalidArgumentException('Deprecation reason cannot be empty');
    }

    // Prevent HTML injection
    if ($trimmedReason !== strip_tags($trimmedReason)) {
        throw new \InvalidArgumentException('Deprecation reason cannot contain HTML tags');
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
            throw new \InvalidArgumentException('Deprecation reason contains potentially malicious content');
        }
    }
}
```

Always escape when rendering:

```php
// In Blade templates
{{ $deprecated->reason }} // Auto-escaped

// In logs
Log::warning('Deprecated API used', [
    'reason' => $deprecated->reason, // Laravel escapes log context
]);
```

---

## Performance Concerns

### 🟢 Performance: Excellent

**Assessment:**
Lightweight DTO with minimal overhead. No performance concerns.

**Observations:**
- Readonly properties prevent defensive copying
- Simple string storage
- No complex computations

---

## Maintainability Assessment

### Code Readability: Excellent ✅
- Clear, descriptive property names
- Comprehensive PHPDoc
- Well-documented purpose and usage

### Documentation Quality: Excellent ✅
- Detailed parameter descriptions
- ISO 8601 format example provided
- Links to extension documentation
- Clear explanation of deprecation semantics

### Testing Considerations

**Recommended Test Cases:**

```php
// tests/Unit/Discovery/DeprecatedDataTest.php
<?php declare(strict_types=1);

namespace Tests\Unit\Discovery;

use Cline\Forrst\Discovery\DeprecatedData;
use Cline\Forrst\Discovery\DeprecationLevel;
use PHPUnit\Framework\TestCase;

final class DeprecatedDataTest extends TestCase
{
    /** @test */
    public function it_creates_deprecation_with_reason_only(): void
    {
        $deprecated = DeprecatedData::create(
            reason: 'Use newFunction() instead'
        );

        $this->assertSame('Use newFunction() instead', $deprecated->reason);
        $this->assertNull($deprecated->sunset);
    }

    /** @test */
    public function it_creates_deprecation_with_sunset_only(): void
    {
        $deprecated = DeprecatedData::create(
            sunsetDate: '2025-12-31'
        );

        $this->assertNull($deprecated->reason);
        $this->assertNotNull($deprecated->sunset);
        $this->assertSame('2025-12-31', $deprecated->getSunsetString());
    }

    /** @test */
    public function it_creates_deprecation_with_both_fields(): void
    {
        $deprecated = DeprecatedData::create(
            reason: 'Security vulnerability fixed in v2',
            sunsetDate: '2025-06-30'
        );

        $this->assertSame('Security vulnerability fixed in v2', $deprecated->reason);
        $this->assertSame('2025-06-30', $deprecated->getSunsetString());
    }

    /** @test */
    public function it_validates_iso_8601_date_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sunset date format');

        DeprecatedData::create(sunsetDate: '12/31/2025'); // US format, not ISO 8601
    }

    /** @test */
    public function it_rejects_past_sunset_dates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is in the past');

        DeprecatedData::create(sunsetDate: '2020-01-01');
    }

    /** @test */
    public function it_requires_at_least_one_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one field');

        new DeprecatedData();
    }

    /** @test */
    public function it_rejects_empty_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        DeprecatedData::create(reason: '   ');
    }

    /** @test */
    public function it_rejects_too_short_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 10 characters');

        DeprecatedData::create(reason: 'Too short');
    }

    /** @test */
    public function it_calculates_days_until_sunset(): void
    {
        $futureDate = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d');
        $deprecated = DeprecatedData::create(sunsetDate: $futureDate);

        $days = $deprecated->getDaysUntilSunset();

        $this->assertGreaterThanOrEqual(29, $days);
        $this->assertLessThanOrEqual(30, $days);
    }

    /** @test */
    public function it_detects_approaching_sunset(): void
    {
        $futureDate = (new \DateTimeImmutable())->modify('+60 days')->format('Y-m-d');
        $deprecated = DeprecatedData::create(sunsetDate: $futureDate);

        $this->assertTrue($deprecated->isSunsetApproaching(90));
        $this->assertFalse($deprecated->isSunsetApproaching(30));
    }

    /** @test */
    public function it_detects_alternative_suggestions_in_reason(): void
    {
        $withSuggestion = DeprecatedData::create(
            reason: 'This method is deprecated. Use createUser() instead.'
        );

        $withoutSuggestion = DeprecatedData::create(
            reason: 'This method is no longer supported.'
        );

        $this->assertTrue($withSuggestion->hasAlternativeSuggestion());
        $this->assertFalse($withoutSuggestion->hasAlternativeSuggestion());
    }

    /** @test */
    public function it_formats_warning_messages(): void
    {
        $deprecated = DeprecatedData::create(
            reason: 'Use v2 API instead',
            sunsetDate: (new \DateTimeImmutable())->modify('+90 days')->format('Y-m-d')
        );

        $message = $deprecated->getWarningMessage('getUserById()');

        $this->assertStringContainsString('DEPRECATED', $message);
        $this->assertStringContainsString('getUserById()', $message);
        $this->assertStringContainsString('Use v2 API instead', $message);
        $this->assertStringContainsString('removed on', $message);
    }

    /** @test */
    public function it_rejects_html_in_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain HTML tags');

        DeprecatedData::create(
            reason: '<script>alert("XSS")</script> Use v2 instead'
        );
    }

    /** @test */
    public function it_supports_deprecation_levels(): void
    {
        $softDeprecated = new DeprecatedData(
            reason: 'Consider using v2',
            level: DeprecationLevel::SOFT
        );

        $hardDeprecated = new DeprecatedData(
            reason: 'Will be removed next week!',
            sunset: new \DateTimeImmutable('+7 days'),
            level: DeprecationLevel::HARD
        );

        $this->assertFalse($softDeprecated->level->isUrgent());
        $this->assertTrue($hardDeprecated->level->isUrgent());
    }

    /** @test */
    public function readonly_properties_are_immutable(): void
    {
        $deprecated = DeprecatedData::create(reason: 'Test');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $deprecated->reason = 'Modified'; // @phpstan-ignore-line
    }
}
```

---

## Summary of Recommendations

### Critical (Must Fix) 🔴
None identified.

### Major (Should Fix Soon) 🟠
1. **Add sunset date validation** (Line 42) - Use `DateTimeImmutable` instead of string, validate ISO 8601 format, reject past dates

### Minor (Consider Fixing) 🟡
1. **Enforce migration guidance** (Line 41) - Validate that reason field includes alternative suggestions
2. **Add deprecation levels** (Class-level) - Implement severity levels (soft, standard, hard, security)
3. **Prevent injection attacks** (Line 41) - Validate reason field to prevent HTML/JavaScript injection

### Suggestions (Optional Improvements) 🔵
1. **Add utility methods** - Implement `hasSunsetPassed()`, `getDaysUntilSunset()`, `isSunsetApproaching()`, `getWarningMessage()`
2. **Add comprehensive unit tests** - Cover all validation, date calculations, and edge cases
3. **Consider markdown support** - Allow markdown formatting in reason field for better documentation

---

## Conclusion

**Overall Rating: 7/10**

DeprecatedData.php is a well-documented, simple DTO that effectively represents deprecation metadata. The class follows good practices with readonly properties and clear documentation. However, it suffers from weak type safety and validation:

**Strengths:**
- Clear purpose and documentation
- Simple, focused design
- Good use of readonly properties

**Weaknesses:**
- String-based sunset dates instead of proper date objects
- No validation of date formats or values
- Missing utility methods for common deprecation queries
- No severity levels for phased deprecations

Implementing the recommended changes would transform this from a basic data holder into a robust deprecation management tool that provides better developer experience, type safety, and validation.

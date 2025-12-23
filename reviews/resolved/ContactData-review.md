# Code Review: ContactData.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Discovery/ContactData.php`
**Purpose:** Defines a Data Transfer Object (DTO) for contact information in API discovery documents. Provides contact details for individuals or teams responsible for the API to facilitate support and communication.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) ✅
**Rating: Excellent**

The class has a single, focused responsibility: representing contact information. It contains only contact-related data without mixing concerns.

### Open/Closed Principle (OCP) ✅
**Rating: Good**

As a `final` class, it's appropriately closed for modification. Extension through composition is possible if needed.

### Liskov Substitution Principle (LSP) ✅
**Rating: Good**

Properly extends `Spatie\LaravelData\Data` without behavioral violations.

### Interface Segregation Principle (ISP) ✅
**Rating: Good**

All properties are cohesive and related to contact information. No unnecessary dependencies.

### Dependency Inversion Principle (DIP) ✅
**Rating: Good**

Minimal dependencies, only on the base `Data` class which is appropriate for DTOs.

---

## Code Quality Issues

### 🟠 Major Issue: Missing Input Validation
**Location:** Lines 39-43 (Constructor)

**Issue:** The constructor accepts string values without validation. Invalid email addresses, malformed URLs, or excessively long names can be accepted without any checks.

**Impact:**
- Invalid email addresses stored in discovery documents
- Broken or malicious URLs in contact information
- Potential security vulnerabilities (XSS, phishing)
- Poor data quality in API documentation
- Email delivery failures when attempting to contact teams

**Solution:**
Add validation in the constructor:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Contact information for the API service or team.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 */
final class ContactData extends Data
{
    /**
     * Create a new contact information instance.
     *
     * @param null|string $name  The name of the contact person, team, or organization
     * @param null|string $url   The URL to a web page, documentation site, or contact form
     * @param null|string $email The email address for contacting the API team
     * @throws \InvalidArgumentException if validation fails
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $url = null,
        public readonly ?string $email = null,
    ) {
        $this->validate();
    }

    /**
     * Validate contact information fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->name !== null) {
            $this->validateName();
        }

        if ($this->url !== null) {
            $this->validateUrl();
        }

        if ($this->email !== null) {
            $this->validateEmail();
        }

        $this->validateAtLeastOneFieldPresent();
    }

    /**
     * Validate the name field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateName(): void
    {
        $trimmedName = trim($this->name);

        if ($trimmedName === '') {
            throw new \InvalidArgumentException('Contact name cannot be empty or whitespace only');
        }

        if (mb_strlen($trimmedName) > 255) {
            throw new \InvalidArgumentException(
                sprintf('Contact name cannot exceed 255 characters, got %d', mb_strlen($trimmedName))
            );
        }

        // Prevent HTML/script injection
        if ($trimmedName !== strip_tags($trimmedName)) {
            throw new \InvalidArgumentException('Contact name cannot contain HTML tags');
        }
    }

    /**
     * Validate the URL field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateUrl(): void
    {
        if (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid contact URL format: %s', $this->url)
            );
        }

        $parsedUrl = parse_url($this->url);

        if (!isset($parsedUrl['scheme']) || !in_array($parsedUrl['scheme'], ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Contact URL must use HTTP or HTTPS protocol, got: %s', $parsedUrl['scheme'] ?? 'none')
            );
        }

        // Prevent javascript: and data: URLs
        $scheme = strtolower($parsedUrl['scheme']);
        if (in_array($scheme, ['javascript', 'data', 'vbscript', 'file'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Contact URL scheme "%s" is not allowed for security reasons', $scheme)
            );
        }
    }

    /**
     * Validate the email field.
     *
     * @throws \InvalidArgumentException
     */
    private function validateEmail(): void
    {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid email address format: %s', $this->email)
            );
        }

        // Additional RFC 5322 validation
        if (mb_strlen($this->email) > 254) {
            throw new \InvalidArgumentException(
                sprintf('Email address cannot exceed 254 characters, got %d', mb_strlen($this->email))
            );
        }

        // Prevent obviously fake/dangerous emails
        $suspiciousDomains = ['example.com', 'test.com', 'localhost'];
        $emailDomain = substr(strrchr($this->email, '@'), 1);

        if (in_array(strtolower($emailDomain), $suspiciousDomains, true)) {
            throw new \InvalidArgumentException(
                sprintf('Email domain "%s" appears to be a placeholder or test domain', $emailDomain)
            );
        }
    }

    /**
     * Validate that at least one contact method is provided.
     *
     * @throws \InvalidArgumentException
     */
    private function validateAtLeastOneFieldPresent(): void
    {
        if ($this->name === null && $this->url === null && $this->email === null) {
            throw new \InvalidArgumentException(
                'ContactData requires at least one field (name, url, or email) to be provided'
            );
        }
    }
}
```

### 🟡 Minor Issue: No Normalization of Input Data
**Location:** Lines 40-42

**Issue:** Input strings are not trimmed or normalized, leading to inconsistent data (leading/trailing whitespace, mixed case emails).

**Impact:**
- Inconsistent email addresses (e.g., " user@example.com" vs "user@example.com")
- Display issues in documentation
- Email delivery problems due to whitespace

**Solution:**
Add normalization in the constructor (after validation code above):

```php
public function __construct(
    ?string $name = null,
    ?string $url = null,
    ?string $email = null,
) {
    // Normalize inputs before assignment
    $this->name = $name !== null ? trim($name) : null;
    $this->url = $url !== null ? rtrim($url, '/') : null; // Remove trailing slashes
    $this->email = $email !== null ? strtolower(trim($email)) : null; // Emails are case-insensitive

    // Note: PHP 8.x readonly properties must be set during object initialization
    // The above won't work with readonly. Instead, validate before the constructor:

    // Better approach with readonly:
    // Create static factory method
}
```

Since readonly properties can't be modified after construction, use a static factory method:

```php
/**
 * Create contact data with normalized inputs.
 *
 * @param null|string $name Contact name
 * @param null|string $url Contact URL
 * @param null|string $email Contact email
 * @return self
 */
public static function create(
    ?string $name = null,
    ?string $url = null,
    ?string $email = null,
): self {
    return new self(
        name: $name !== null ? trim($name) : null,
        url: $url !== null ? rtrim($url, '/') : null,
        email: $email !== null ? strtolower(trim($email)) : null,
    );
}
```

### 🟡 Minor Issue: Missing Named Constructor Methods
**Location:** Class-level

**Issue:** No convenience methods for common contact creation scenarios.

**Impact:** Verbose code when creating contact information in common patterns.

**Solution:**
Add static factory methods:

```php
/**
 * Create contact with email only.
 *
 * @param string $email Contact email address
 * @return self
 */
public static function email(string $email): self
{
    return new self(email: $email);
}

/**
 * Create contact with name and email.
 *
 * @param string $name Contact name
 * @param string $email Contact email address
 * @return self
 */
public static function person(string $name, string $email): self
{
    return new self(name: $name, email: $email);
}

/**
 * Create contact with all information.
 *
 * @param string $name Contact name
 * @param string $url Contact URL
 * @param string $email Contact email address
 * @return self
 */
public static function full(string $name, string $url, string $email): self
{
    return new self(name: $name, url: $url, email: $email);
}

/**
 * Create contact for a team.
 *
 * @param string $teamName Team name
 * @param string $url Team documentation or support URL
 * @param string $email Team email address
 * @return self
 */
public static function team(string $teamName, string $url, string $email): self
{
    return new self(name: $teamName, url: $url, email: $email);
}
```

### 🔵 Suggestion: Add Utility Methods
**Location:** Class-level

**Issue:** No helper methods to check which contact methods are available or to format contact info for display.

**Impact:** Consumers must manually check nullable properties and format output.

**Solution:**
Add utility methods:

```php
/**
 * Check if contact information is complete (all fields present).
 *
 * @return bool
 */
public function isComplete(): bool
{
    return $this->name !== null
        && $this->url !== null
        && $this->email !== null;
}

/**
 * Check if email contact is available.
 *
 * @return bool
 */
public function hasEmail(): bool
{
    return $this->email !== null;
}

/**
 * Check if URL contact is available.
 *
 * @return bool
 */
public function hasUrl(): bool
{
    return $this->url !== null;
}

/**
 * Check if name is available.
 *
 * @return bool
 */
public function hasName(): bool
{
    return $this->name !== null;
}

/**
 * Format contact information as a string.
 *
 * @return string
 */
public function format(): string
{
    $parts = array_filter([
        $this->name,
        $this->email ? "<{$this->email}>" : null,
        $this->url,
    ]);

    return implode(' - ', $parts);
}

/**
 * Convert to mailto link HTML.
 *
 * @return null|string HTML mailto link or null if no email
 */
public function toMailtoLink(): ?string
{
    if ($this->email === null) {
        return null;
    }

    $displayName = $this->name ?? $this->email;

    return sprintf(
        '<a href="mailto:%s">%s</a>',
        htmlspecialchars($this->email, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')
    );
}
```

---

## Security Vulnerabilities

### 🔴 Critical Security Issue: XSS Vulnerability in Name Field
**Location:** Line 40

**Issue:** The `$name` field accepts arbitrary strings without sanitization. If rendered in HTML contexts without escaping, this could lead to Cross-Site Scripting (XSS) attacks.

**Impact:**
- Attackers could inject malicious JavaScript
- Session hijacking through cookie theft
- Defacement of documentation pages
- Phishing attacks on API consumers

**Solution:**
Implement validation in the constructor (shown above in Code Quality section) that strips HTML tags and validates the name field. Additionally, ensure all rendering contexts escape output:

```php
// When rendering in Blade templates:
{{ $contact->name }} // Auto-escaped

// When rendering in JavaScript contexts:
<script>
    const contactName = @json($contact->name); // JSON-encoded, safe
</script>

// When rendering in HTML attributes:
<div data-contact="{{ $contact->name }}"> // Auto-escaped
```

### 🟠 Major Security Issue: Open Redirect in URL Field
**Location:** Line 41

**Issue:** The `$url` field accepts any URL without validation. Malicious URLs could redirect users to phishing sites or trigger security warnings.

**Impact:**
- Open redirect vulnerability
- Phishing attacks
- Malware distribution
- Damage to API credibility

**Solution:**
Validate URLs in constructor (shown above in Code Quality section). Additionally, consider implementing URL allowlisting:

```php
/**
 * Validate URL against an allowlist of domains.
 *
 * @param array<string> $allowedDomains Optional list of allowed domains
 * @throws \InvalidArgumentException
 */
private function validateUrlAgainstAllowlist(array $allowedDomains = []): void
{
    if (empty($allowedDomains)) {
        return; // No allowlist configured
    }

    $parsedUrl = parse_url($this->url);
    $host = $parsedUrl['host'] ?? '';

    foreach ($allowedDomains as $allowedDomain) {
        if (str_ends_with($host, $allowedDomain)) {
            return; // Domain is allowed
        }
    }

    throw new \InvalidArgumentException(
        sprintf('Contact URL domain "%s" is not in the allowed domains list', $host)
    );
}
```

### 🟡 Minor Security Issue: Email Enumeration
**Location:** Line 42

**Issue:** Exposing email addresses publicly in discovery documents could lead to spam, phishing, or email enumeration attacks.

**Impact:**
- Increased spam and phishing attempts
- Privacy concerns for individuals
- Potential GDPR/privacy compliance issues

**Solution:**
Consider adding an option to obfuscate emails or use contact forms instead:

```php
/**
 * Get obfuscated email for public display.
 *
 * @return null|string Obfuscated email or null
 */
public function getObfuscatedEmail(): ?string
{
    if ($this->email === null) {
        return null;
    }

    [$local, $domain] = explode('@', $this->email);

    // Obfuscate local part: show first 2 and last 1 characters
    $localLength = mb_strlen($local);

    if ($localLength <= 3) {
        $obfuscatedLocal = str_repeat('*', $localLength);
    } else {
        $obfuscatedLocal = mb_substr($local, 0, 2)
            . str_repeat('*', $localLength - 3)
            . mb_substr($local, -1);
    }

    return $obfuscatedLocal . '@' . $domain;
}
```

---

## Performance Concerns

### 🟢 Performance: Excellent

**Assessment:**
This is a lightweight DTO with minimal overhead. No performance concerns identified.

**Observations:**
- Readonly properties prevent unnecessary object cloning
- No complex computations
- Minimal memory footprint

---

## Maintainability Assessment

### Code Readability: Excellent ✅
- Clear, self-documenting property names
- Comprehensive PHPDoc
- Simple, straightforward structure

### Documentation Quality: Excellent ✅
- Detailed parameter descriptions
- Usage context provided
- Reference links included

### Testing Considerations

**Recommended Test Cases:**

```php
// tests/Unit/Discovery/ContactDataTest.php
<?php declare(strict_types=1);

namespace Tests\Unit\Discovery;

use Cline\Forrst\Discovery\ContactData;
use PHPUnit\Framework\TestCase;

final class ContactDataTest extends TestCase
{
    /** @test */
    public function it_creates_contact_with_all_fields(): void
    {
        $contact = new ContactData(
            name: 'API Support Team',
            url: 'https://support.example.com',
            email: 'support@example.com',
        );

        $this->assertSame('API Support Team', $contact->name);
        $this->assertSame('https://support.example.com', $contact->url);
        $this->assertSame('support@example.com', $contact->email);
    }

    /** @test */
    public function it_creates_contact_with_email_only(): void
    {
        $contact = ContactData::email('api@example.com');

        $this->assertNull($contact->name);
        $this->assertNull($contact->url);
        $this->assertSame('api@example.com', $contact->email);
    }

    /** @test */
    public function it_validates_email_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid email address format');

        new ContactData(email: 'not-an-email');
    }

    /** @test */
    public function it_validates_url_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid contact URL format');

        new ContactData(url: 'not a url');
    }

    /** @test */
    public function it_rejects_javascript_urls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed for security reasons');

        new ContactData(url: 'javascript:alert("XSS")');
    }

    /** @test */
    public function it_rejects_html_in_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot contain HTML tags');

        new ContactData(name: '<script>alert("XSS")</script>');
    }

    /** @test */
    public function it_normalizes_email_to_lowercase(): void
    {
        $contact = ContactData::create(email: 'Support@Example.COM');

        $this->assertSame('support@example.com', $contact->email);
    }

    /** @test */
    public function it_trims_whitespace_from_inputs(): void
    {
        $contact = ContactData::create(
            name: '  API Team  ',
            email: '  support@example.com  ',
        );

        $this->assertSame('API Team', $contact->name);
        $this->assertSame('support@example.com', $contact->email);
    }

    /** @test */
    public function it_requires_at_least_one_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one field');

        new ContactData();
    }

    /** @test */
    public function it_formats_contact_information(): void
    {
        $contact = new ContactData(
            name: 'John Doe',
            url: 'https://example.com',
            email: 'john@example.com',
        );

        $formatted = $contact->format();

        $this->assertStringContainsString('John Doe', $formatted);
        $this->assertStringContainsString('john@example.com', $formatted);
        $this->assertStringContainsString('https://example.com', $formatted);
    }

    /** @test */
    public function it_generates_mailto_link(): void
    {
        $contact = new ContactData(
            name: 'Support',
            email: 'support@example.com',
        );

        $mailtoLink = $contact->toMailtoLink();

        $this->assertStringContainsString('mailto:support@example.com', $mailtoLink);
        $this->assertStringContainsString('Support', $mailtoLink);
    }

    /** @test */
    public function it_obfuscates_email_for_public_display(): void
    {
        $contact = new ContactData(email: 'john.doe@example.com');

        $obfuscated = $contact->getObfuscatedEmail();

        $this->assertStringNotContainsString('john.doe', $obfuscated);
        $this->assertStringContainsString('@example.com', $obfuscated);
        $this->assertMatchesRegularExpression('/jo\*+e@example\.com/', $obfuscated);
    }

    /** @test */
    public function readonly_properties_are_immutable(): void
    {
        $contact = new ContactData(email: 'test@example.com');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $contact->email = 'modified@example.com'; // @phpstan-ignore-line
    }
}
```

---

## Summary of Recommendations

### Critical (Must Fix) 🔴
1. **Add XSS protection** (Line 40) - Validate and sanitize the `$name` field to prevent HTML/JavaScript injection

### Major (Should Fix Soon) 🟠
1. **Add input validation** (Lines 39-43) - Validate email format, URL format, and field constraints in constructor
2. **Prevent open redirect** (Line 41) - Validate URLs to ensure they use safe protocols and optionally implement domain allowlisting

### Minor (Consider Fixing) 🟡
1. **Add input normalization** (Lines 40-42) - Trim whitespace and normalize email casing via static factory method
2. **Add named constructors** - Implement `email()`, `person()`, `full()`, and `team()` factory methods
3. **Email privacy** (Line 42) - Consider adding email obfuscation for public display

### Suggestions (Optional Improvements) 🔵
1. **Add utility methods** - Implement `hasEmail()`, `hasUrl()`, `hasName()`, `isComplete()`, `format()`, `toMailtoLink()`
2. **Add comprehensive unit tests** - Cover all validation, edge cases, and security scenarios

---

## Conclusion

**Overall Rating: 6.5/10**

ContactData.php is a simple, well-documented DTO that effectively represents contact information. However, it lacks critical input validation and security measures that are essential for handling user-supplied data in public-facing discovery documents. The main concerns are:

1. **Security**: Missing validation allows XSS attacks and open redirects
2. **Data Quality**: No email/URL format validation leads to invalid data
3. **User Experience**: Missing normalization causes inconsistent data

Implementing the recommended validation, sanitization, and factory methods would elevate this class from basic to production-ready, ensuring data integrity and security while improving developer experience.

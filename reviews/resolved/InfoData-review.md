# Code Review: InfoData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/InfoData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

InfoData represents service metadata for discovery documents. Well-structured with comprehensive documentation, but lacks validation for required fields, semantic versioning, and URL format for termsOfService.

**Overall Assessment:** 🟠 Major Issues
**Recommendation:** Add validation for title, version, and URLs

---

## SOLID Principles Analysis

All principles satisfied. Clean DTO design.

---

## Code Quality Issues

### 🔴 CRITICAL: No Validation for Required Fields (Lines 50-57)

**Issue:** Required `$title` and `$version` fields have no validation.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/InfoData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $title,
    public readonly string $version,
    public readonly ?string $description = null,
    public readonly ?string $termsOfService = null,
    public readonly ?ContactData $contact = null,
    public readonly ?LicenseData $license = null,
) {
    // Validate title
    $trimmedTitle = trim($title);
    if ($trimmedTitle === '') {
        throw new InvalidArgumentException('Service title cannot be empty');
    }

    if (mb_strlen($trimmedTitle) > 200) {
        throw new InvalidArgumentException(
            'Service title too long (max 200 characters, got ' . mb_strlen($trimmedTitle) . ')'
        );
    }

    $this->title = $trimmedTitle;

    // Validate semantic version
    $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
        '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)' .
        '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'  .
        '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    if (!preg_match($semverPattern, $version)) {
        throw new InvalidArgumentException(
            "Invalid semantic version: '{$version}'. Must follow semver format (e.g., '2.1.0')"
        );
    }

    // Validate termsOfService URL if provided
    if ($this->termsOfService !== null) {
        $this->validateUrl($this->termsOfService, 'Terms of Service');
    }
}

/**
 * Validate URL format.
 *
 * @throws InvalidArgumentException
 */
private function validateUrl(string $url, string $fieldName): void
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException(
            "{$fieldName} must be valid URL. Got: '{$url}'"
        );
    }

    $parsed = parse_url($url);
    
    if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
        throw new InvalidArgumentException(
            "{$fieldName} URL must use http or https protocol"
        );
    }
}
```

### 🟡 MINOR: Description Length Unconstrained (Line 53)

**Issue:** Description has no maximum length validation.

**Solution:**
```php
if ($this->description !== null && mb_strlen($this->description) > 5000) {
    throw new InvalidArgumentException(
        'Description too long (max 5000 characters, got ' . mb_strlen($this->description) . ')'
    );
}
```

---

## Test Coverage

```php
it('creates valid service info', function () {
    $info = new InfoData(
        title: 'Payment API',
        version: '2.1.0',
        description: 'Process payments securely',
        termsOfService: 'https://example.com/terms',
    );

    expect($info->title)->toBe('Payment API')
        ->and($info->version)->toBe('2.1.0');
});

it('rejects empty title', function () {
    expect(fn() => new InfoData(
        title: '',
        version: '1.0.0',
    ))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('rejects invalid semantic version', function () {
    expect(fn() => new InfoData(
        title: 'Test',
        version: 'v1',
    ))->toThrow(InvalidArgumentException::class, 'Invalid semantic version');
});

it('rejects invalid termsOfService URL', function () {
    expect(fn() => new InfoData(
        title: 'Test',
        version: '1.0.0',
        termsOfService: 'not-a-url',
    ))->toThrow(InvalidArgumentException::class, 'must be valid URL');
});

it('trims whitespace from title', function () {
    $info = new InfoData(
        title: '  Test API  ',
        version: '1.0.0',
    );

    expect($info->title)->toBe('Test API');
});
```

---

## Summary

### Critical Issues
1. 🔴 Validate title (emptiness, length)
2. 🔴 Validate semantic version format
3. 🔴 Validate termsOfService URL format

### Minor Issues
4. 🟡 Add description length validation

### Estimated Effort: 2-3 hours

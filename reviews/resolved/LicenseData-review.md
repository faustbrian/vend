# Code Review: LicenseData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/LicenseData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

LicenseData is a simple, minimal DTO for license information. Clean design but lacks validation for required name field and URL format.

**Overall Assessment:** 🟡 Minor Issues
**Recommendation:** Add name and URL validation

---

## Code Quality Issues

### 🟠 MAJOR: No Name Validation (Line 41)

**Issue:** Required `$name` field has no validation for emptiness or format.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/LicenseData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $name,
    public readonly ?string $url = null,
) {
    // Validate name
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('License name cannot be empty');
    }

    if (mb_strlen($trimmedName) > 100) {
        throw new InvalidArgumentException(
            'License name too long (max 100 characters, got ' . mb_strlen($trimmedName) . ')'
        );
    }

    $this->name = $trimmedName;

    // Validate URL if provided
    if ($this->url !== null && !filter_var($this->url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException(
            "License URL must be valid URL. Got: '{$this->url}'"
        );
    }

    if ($this->url !== null) {
        $parsed = parse_url($this->url);
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('License URL must use http or https protocol');
        }
    }
}
```

---

## Test Coverage

```php
it('creates valid license', function () {
    $license = new LicenseData(
        name: 'MIT',
        url: 'https://opensource.org/licenses/MIT',
    );

    expect($license->name)->toBe('MIT');
});

it('rejects empty name', function () {
    expect(fn() => new LicenseData(name: ''))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('rejects invalid URL', function () {
    expect(fn() => new LicenseData(
        name: 'MIT',
        url: 'not-a-url',
    ))->toThrow(InvalidArgumentException::class, 'valid URL');
});
```

---

## Summary

### Major Issues
1. 🟠 Validate license name (emptiness, length)
2. 🟠 Validate URL format

### Estimated Effort: 1-2 hours

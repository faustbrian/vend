# Code Review: TagData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/TagData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

TagData is a simple DTO for organizing functions into logical groups. Clean design with comprehensive documentation, but lacks validation for required name field and naming convention enforcement.

**Overall Assessment:** 🟡 Minor Issues
**Recommendation:** Add name validation and naming convention checks

---

## SOLID Principles Analysis

All principles satisfied. Simple, focused DTO.

---

## Code Quality Issues

### 🟠 MAJOR: No Name Validation (Line 50)

**Issue:** Required `$name` field has no validation for emptiness, format, or naming convention.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/TagData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $name,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly ?ExternalDocsData $externalDocs = null,
) {
    // Validate name
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('Tag name cannot be empty');
    }

    if (mb_strlen($trimmedName) > 50) {
        throw new InvalidArgumentException(
            'Tag name too long (max 50 characters, got ' . mb_strlen($trimmedName) . ')'
        );
    }

    // Recommend kebab-case or snake_case for consistency
    if (!preg_match('/^[a-z][a-z0-9_-]*$/', $trimmedName)) {
        trigger_error(
            "Warning: Tag name '{$trimmedName}' should use lowercase kebab-case or snake_case " .
            "(e.g., 'user-management', 'billing', 'analytics')",
            E_USER_WARNING
        );
    }

    $this->name = $trimmedName;

    // Validate summary length
    if ($this->summary !== null && mb_strlen($this->summary) > 60) {
        trigger_error(
            'Warning: Tag summary should be brief (under 60 characters). Got ' . mb_strlen($this->summary),
            E_USER_WARNING
        );
    }
}
```

---

## Test Coverage

```php
it('creates valid tag', function () {
    $tag = new TagData(
        name: 'user-management',
        summary: 'User account operations',
        description: 'Functions for creating, updating, and deleting user accounts',
    );

    expect($tag->name)->toBe('user-management');
});

it('rejects empty tag name', function () {
    expect(fn() => new TagData(name: ''))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('trims whitespace from name', function () {
    $tag = new TagData(name: '  billing  ');
    expect($tag->name)->toBe('billing');
});

it('rejects excessively long tag names', function () {
    expect(fn() => new TagData(
        name: str_repeat('a', 51),
    ))->toThrow(InvalidArgumentException::class, 'too long');
});

it('warns about non-standard naming', function () {
    expect(fn() => new TagData(
        name: 'InvalidCase',
    ))->toTrigger(E_USER_WARNING, 'kebab-case or snake_case');
});

it('warns about long summaries', function () {
    expect(fn() => new TagData(
        name: 'test',
        summary: str_repeat('a', 61),
    ))->toTrigger(E_USER_WARNING, 'brief');
});

it('accepts external documentation', function () {
    $tag = new TagData(
        name: 'payments',
        externalDocs: new ExternalDocsData(
            url: 'https://docs.example.com/payments',
            description: 'Payment API Guide',
        ),
    );

    expect($tag->externalDocs)->toBeInstanceOf(ExternalDocsData::class);
});
```

---

## Summary

### Major Issues
1. 🟠 Validate tag name (emptiness, length, format)

### Minor Issues
2. 🟡 Validate summary length
3. 🟡 Enforce naming convention

### Estimated Effort: 1-2 hours

Simple DTO requiring basic validation.

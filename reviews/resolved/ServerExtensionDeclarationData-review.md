# Code Review: ServerExtensionDeclarationData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/ServerExtensionDeclarationData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

ServerExtensionDeclarationData is a minimal DTO for declaring server-supported extensions. Clean design but lacks URN format validation and semantic version enforcement.

**Overall Assessment:** 🟠 Major Issues
**Recommendation:** Add URN and version validation

---

## Code Quality Issues

### 🔴 CRITICAL: No URN Format Validation (Line 39)

**Issue:** Extension URN accepts any string without format validation.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/ServerExtensionDeclarationData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $urn,
    public readonly string $version,
) {
    // Validate URN format: urn:forrst:ext:name
    if (!preg_match('/^urn:forrst:ext:[a-z][a-z0-9-]*$/', $this->urn)) {
        throw new InvalidArgumentException(
            "Invalid extension URN: '{$this->urn}'. " .
            "Expected format: 'urn:forrst:ext:extensionname' (e.g., 'urn:forrst:ext:async')"
        );
    }

    // Validate semantic version
    $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
        '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)' .
        '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'  .
        '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    if (!preg_match($semverPattern, $this->version)) {
        throw new InvalidArgumentException(
            "Invalid semantic version: '{$this->version}'. Must follow semver format"
        );
    }
}
```

---

## Test Coverage

```php
it('creates valid extension declaration', function () {
    $ext = new ServerExtensionDeclarationData(
        urn: 'urn:forrst:ext:async',
        version: '1.0.0',
    );

    expect($ext->urn)->toBe('urn:forrst:ext:async');
});

it('rejects invalid URN format', function () {
    expect(fn() => new ServerExtensionDeclarationData(
        urn: 'invalid-urn',
        version: '1.0.0',
    ))->toThrow(InvalidArgumentException::class, 'Invalid extension URN');
});

it('rejects invalid semantic version', function () {
    expect(fn() => new ServerExtensionDeclarationData(
        urn: 'urn:forrst:ext:test',
        version: 'v1',
    ))->toThrow(InvalidArgumentException::class, 'Invalid semantic version');
});
```

---

## Summary

### Critical Issues
1. 🔴 Validate URN format
2. 🔴 Validate semantic version

### Estimated Effort: 1-2 hours

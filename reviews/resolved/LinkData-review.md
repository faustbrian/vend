# Code Review: LinkData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/LinkData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

LinkData represents function relationships and navigation links in discovery documents. Well-designed with runtime expression support, but lacks validation for required name field, parameter structure, and circular reference detection.

**Overall Assessment:** 🟠 Major Issues
**Recommendation:** Add name validation and parameter structure checks

---

## Code Quality Issues

### 🟠 MAJOR: No Name Validation (Line 50)

**Issue:** Required `$name` field lacks validation.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/LinkData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $name,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly ?string $function = null,
    public readonly ?array $params = null,
    public readonly ?DiscoveryServerData $server = null,
) {
    // Validate name
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('Link name cannot be empty');
    }

    if (mb_strlen($trimmedName) > 100) {
        throw new InvalidArgumentException(
            'Link name too long (max 100 characters, got ' . mb_strlen($trimmedName) . ')'
        );
    }

    $this->name = $trimmedName;

    // Validate params structure if provided
    if ($this->params !== null) {
        $this->validateParams($this->params);
    }

    // Validate function name format if provided
    if ($this->function !== null) {
        if (!preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)*$/', $this->function)) {
            trigger_error(
                "Warning: Function name '{$this->function}' should use dot notation (e.g., 'users.get', 'orders.create')",
                E_USER_WARNING
            );
        }
    }
}

/**
 * Validate params structure.
 *
 * @param array<string, mixed> $params
 * @throws InvalidArgumentException
 */
private function validateParams(array $params): void
{
    foreach ($params as $paramName => $paramValue) {
        if (!is_string($paramName) || trim($paramName) === '') {
            throw new InvalidArgumentException('Parameter names must be non-empty strings');
        }

        // Check for runtime expression syntax: $result.field
        if (is_string($paramValue) && str_starts_with($paramValue, '$')) {
            if (!preg_match('/^\$result\.[a-zA-Z_][a-zA-Z0-9_.]*$/', $paramValue)) {
                trigger_error(
                    "Warning: Parameter '{$paramName}' uses runtime expression but may have invalid syntax: '{$paramValue}'",
                    E_USER_WARNING
                );
            }
        }
    }
}
```

### 🟡 MINOR: No Circular Reference Detection

**Issue:** Links can reference the same function creating circular navigation.

**Impact:** Infinite loops in documentation navigation.

**Solution:** Document as developer responsibility or add static analysis tool.

---

## Test Coverage

```php
it('creates valid link', function () {
    $link = new LinkData(
        name: 'GetEventVenue',
        summary: 'Retrieve the venue for this event',
        function: 'venues.get',
        params: ['venueId' => '$result.venue.id'],
    );

    expect($link->function)->toBe('venues.get');
});

it('rejects empty name', function () {
    expect(fn() => new LinkData(name: ''))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('validates runtime expressions in params', function () {
    expect(fn() => new LinkData(
        name: 'Test',
        params: ['id' => '$invalid_expr'],
    ))->toTrigger(E_USER_WARNING, 'runtime expression');
});

it('warns about non-standard function names', function () {
    expect(fn() => new LinkData(
        name: 'Test',
        function: 'InvalidFunctionName',
    ))->toTrigger(E_USER_WARNING, 'dot notation');
});
```

---

## Summary

### Major Issues
1. 🟠 Validate link name
2. 🟠 Validate params structure
3. 🟠 Validate runtime expression syntax

### Minor Issues
4. 🟡 Consider circular reference detection

### Estimated Effort: 2-3 hours

# Code Review: SimulationScenarioData.php

**File:** `/Users/brian/Developer/cline/forrst/src/Discovery/SimulationScenarioData.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

SimulationScenarioData defines executable simulation scenarios for sandbox/demo modes. Excellent design with factory methods for success/error scenarios. Primary concerns include missing validation for mutually exclusive output/error fields and input array structure.

**Overall Assessment:** 🟠 Major Issues
**Recommendation:** Add mutually exclusive field validation and input structure checks

---

## Code Quality Issues

### 🔴 CRITICAL: No Validation for Mutually Exclusive Fields (Lines 57-64)

**Issue:** Both `$output` and `$error` can be set simultaneously despite documentation indicating mutual exclusivity for error scenarios.

**Solution:**
```php
// In /Users/brian/Developer/cline/forrst/src/Discovery/SimulationScenarioData.php

use InvalidArgumentException;

public function __construct(
    public readonly string $name,
    public readonly array $input,
    public readonly mixed $output = null,
    public readonly ?string $description = null,
    public readonly ?array $error = null,
    public readonly ?array $metadata = null,
) {
    // Validate name
    $trimmedName = trim($name);
    if ($trimmedName === '') {
        throw new InvalidArgumentException('Scenario name cannot be empty');
    }

    $this->name = $trimmedName;

    // Validate input structure
    if (empty($this->input)) {
        throw new InvalidArgumentException('Scenario input cannot be empty');
    }

    // Validate mutually exclusive output/error
    // Note: output can be null for notification-style functions
    if ($this->output !== null && $this->error !== null) {
        throw new InvalidArgumentException(
            'Cannot specify both "output" and "error". Success scenarios use output, error scenarios use error field.'
        );
    }

    // Validate error structure if provided
    if ($this->error !== null) {
        $this->validateErrorStructure($this->error);
    }
}

/**
 * Validate error array structure.
 *
 * @param array<string, mixed> $error
 * @throws InvalidArgumentException
 */
private function validateErrorStructure(array $error): void
{
    $requiredFields = ['code', 'message'];
    $missingFields = array_diff($requiredFields, array_keys($error));

    if (!empty($missingFields)) {
        throw new InvalidArgumentException(
            'Error structure must include: ' . implode(', ', $requiredFields)
        );
    }

    if (!is_string($error['code']) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $error['code'])) {
        throw new InvalidArgumentException(
            'Error code must be SCREAMING_SNAKE_CASE string'
        );
    }

    if (!is_string($error['message']) || trim($error['message']) === '') {
        throw new InvalidArgumentException('Error message must be non-empty string');
    }
}
```

### 🟡 MINOR: Factory Methods Already Exist ✅

**Assessment:** The class already provides excellent factory methods `success()` and `error()` (lines 76-125). These are well-designed and solve the mutual exclusion problem at the construction level. Great work!

---

## Test Coverage

```php
it('creates success scenario using factory', function () {
    $scenario = SimulationScenarioData::success(
        name: 'user_created',
        input: ['email' => 'test@example.com'],
        output: ['id' => 1, 'email' => 'test@example.com'],
        description: 'User successfully created',
    );

    expect($scenario->output)->toHaveKey('id')
        ->and($scenario->error)->toBeNull();
});

it('creates error scenario using factory', function () {
    $scenario = SimulationScenarioData::error(
        name: 'user_not_found',
        input: ['userId' => 999],
        code: 'USER_NOT_FOUND',
        message: 'User with ID 999 not found',
        description: 'Returns 404 when user doesn't exist',
    );

    expect($scenario->error)->toHaveKey('code')
        ->and($scenario->output)->toBeNull();
});

it('rejects both output and error', function () {
    expect(fn() => new SimulationScenarioData(
        name: 'invalid',
        input: ['id' => 1],
        output: ['success'],
        error: ['code' => 'ERROR', 'message' => 'Failed'],
    ))->toThrow(InvalidArgumentException::class, 'Cannot specify both');
});

it('rejects empty scenario name', function () {
    expect(fn() => SimulationScenarioData::success(
        name: '',
        input: ['data'],
        output: ['result'],
    ))->toThrow(InvalidArgumentException::class, 'cannot be empty');
});

it('rejects empty input array', function () {
    expect(fn() => new SimulationScenarioData(
        name: 'test',
        input: [],
        output: ['result'],
    ))->toThrow(InvalidArgumentException::class, 'input cannot be empty');
});

it('validates error structure', function () {
    expect(fn() => new SimulationScenarioData(
        name: 'test',
        input: ['data'],
        error: ['invalid' => 'structure'], // Missing code and message
    ))->toThrow(InvalidArgumentException::class, 'must include: code, message');
});

it('validates error code format', function () {
    expect(fn() => new SimulationScenarioData(
        name: 'test',
        input: ['data'],
        error: [
            'code' => 'invalid-code', // Not SCREAMING_SNAKE_CASE
            'message' => 'Error',
        ],
    ))->toThrow(InvalidArgumentException::class, 'SCREAMING_SNAKE_CASE');
});
```

---

## Summary

### Critical Issues
1. 🔴 Validate mutually exclusive output/error fields
2. 🔴 Validate error structure (code, message format)

### Major Issues
3. 🟠 Validate scenario name (emptiness, trimming)
4. 🟠 Validate input array is non-empty

### Positive Notes
- ✅ Excellent factory methods already implemented
- ✅ Clear separation between success and error scenarios
- ✅ Good use of metadata field for extensibility

### Estimated Effort: 2-3 hours

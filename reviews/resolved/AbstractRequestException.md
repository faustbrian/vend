# Code Review: AbstractRequestException.php

**File**: `/Users/brian/Developer/cline/forrst/src/Exceptions/AbstractRequestException.php`
**Type**: Abstract Exception Class (Base Foundation)
**Lines of Code**: 210
**Complexity**: Medium-High

---

## Executive Summary

`AbstractRequestException` serves as the foundational exception class for the Forrst RPC protocol implementation. This is an architecturally critical component that standardizes error handling across the entire framework. The implementation demonstrates strong adherence to SOLID principles, comprehensive documentation, and thoughtful error structure design. The class successfully bridges PHP exception semantics with Forrst protocol specifications while maintaining extensibility for concrete exception types.

**Overall Assessment**: 🟢 **EXCELLENT** - Production-ready with minor enhancement opportunities.

---

## Architectural Analysis

### Design Pattern Implementation

**Pattern**: Template Method + Data Transfer Object (DTO)

The class implements a sophisticated template pattern where the abstract base defines the error handling contract while delegating specific error code/message determination to concrete subclasses. The composition with `ErrorData` follows the DTO pattern effectively, separating error structure from exception behavior.

**Strengths**:
- **Separation of Concerns**: Exception behavior (PHP) is cleanly separated from error structure (Forrst protocol)
- **Composition over Inheritance**: Uses `ErrorData` composition rather than bloating the exception hierarchy
- **Open/Closed Principle**: Extensible via subclassing without modifying base behavior
- **Single Responsibility**: Focuses exclusively on error representation and conversion

**Interface Compliance**:
```php
abstract class AbstractRequestException extends Exception implements RpcException
```
The dual inheritance from `Exception` (PHP standard) and `RpcException` (protocol contract) creates a robust foundation. This allows the exception to work seamlessly with both PHP's native exception handling and Forrst-specific error processing.

### Dependency Analysis

**Direct Dependencies**:
- `ErrorData` - Error structure DTO ✅ Appropriate domain coupling
- `SourceData` - Source location metadata ✅ Cohesive relationship
- `ErrorCode` - Enum for standardized codes ✅ Type-safe error identification
- `Illuminate\Support\Arr` - Laravel helper ✅ Appropriate for Laravel package
- `Illuminate\Support\Facades\App` - Debug mode detection ✅ Appropriate for Laravel package

---

## Code Quality Evaluation

### Readability: ⭐⭐⭐⭐⭐ (5/5)

**Exceptional Documentation**:
```php
/**
 * Base exception class for Forrst request errors.
 *
 * Part of the Forrst protocol exception hierarchy. Provides the foundation for all
 * Forrst error handling by encapsulating error data and standardized error response
 * formatting...
 */
```

The class-level DocBlock is comprehensive, providing context, purpose, and architectural role. Every method includes detailed parameter descriptions and return type documentation.

**Naming Conventions**: Crystal clear and self-documenting
- `getErrorCode()` - Unambiguous intent
- `isRetryable()` - Boolean prefix convention
- `toError()` / `toArray()` - Standard conversion naming
- `protected static function new()` - Factory method pattern

### Type Safety: ⭐⭐⭐⭐⭐ (5/5)

**Strict Typing Throughout**:
```php
<?php declare(strict_types=1);
```

Excellent use of:
- Constructor property promotion with readonly modifier
- Nullable return types (`?array`, `?SourceData`)
- Generic array type hints (`array<string, mixed>`)
- PHPStan directives for complex type scenarios

**Null Safety**:
```php
public function isRetryable(): bool
{
    $errorCode = ErrorCode::tryFrom($this->error->code);
    return $errorCode?->isRetryable() ?? false;
}
```

Proper use of null coalescing and null-safe operator prevents runtime errors.

### Error Handling: ⭐⭐⭐⭐ (4/5)

**Strength**: The exception hierarchy design itself is the error handling mechanism.

**Observation**: The `new()` factory method includes a PHPStan suppression:
```php
// @phpstan-ignore-next-line
return new static(
```

🟡 **Minor**: PHPStan suppression without explanation

**Reason**: PHPStan struggles with `new static()` in abstract classes returning `static` type.

**Recommendation**: Add explanatory comment:
```php
/**
 * Create a new exception instance with error details.
 *
 * Factory method for constructing exception instances with standardized error data.
 * Used by concrete exception subclasses to create properly structured exceptions
 * with error codes, messages, source locations, and additional details.
 *
 * @param  ErrorCode                 $code    The Forrst error code enum value
 * @param  string                    $message The human-readable error message
 * @param  null|SourceData           $source  Optional source location
 * @param  null|array<string, mixed> $details Optional additional error context
 * @return static                    The constructed exception instance
 */
protected static function new(
    ErrorCode $code,
    string $message,
    ?SourceData $source = null,
    ?array $details = null,
): static {
    // PHPStan cannot infer that 'new static' returns 'static' type in abstract classes
    // This is a known limitation when using LSB (Late Static Binding) with abstract constructors
    // Safe to suppress as all subclasses must call parent constructor accepting ErrorData
    // @phpstan-ignore-next-line
    return new static(
        new ErrorData(
            code: $code,
            message: $message,
            source: $source,
            details: $details,
        ),
    );
}
```

---

## Security Audit

### 🔵 Information Disclosure Control

**Debug Mode Protection**:
```php
if (App::hasDebugModeEnabled()) {
    Arr::set(
        $message,
        'details.debug',
        [
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString(),
        ],
    );
}
```

✅ **EXCELLENT**: Debug information (file paths, stack traces) is only exposed when debug mode is enabled. This prevents sensitive server path disclosure in production.

**Consideration**: Ensure `App::hasDebugModeEnabled()` properly respects environment configuration and cannot be manipulated by user input.

### 🟢 Input Validation

All inputs to the factory method are strongly typed:
```php
protected static function new(
    ErrorCode $code,        // Enum - type-safe by definition
    string $message,        // Primitive string - no injection risk
    ?SourceData $source,    // Typed object or null
    ?array $details,        // Array type enforced
): static
```

✅ **SECURE**: Type system prevents injection attacks. No string concatenation or dynamic code execution.

### 🟢 Data Exposure

The `toArray()` method applies filtering:
```php
return array_filter($message);
```

✅ **GOOD**: Removes null/false/empty values from response, reducing payload size and potential information leakage.

**Enhancement Opportunity**:

🔵 **Suggestion**: Consider sensitive field filtering

If error details might contain sensitive data (passwords, tokens, PII), add explicit sanitization:

```php
public function toArray(): array
{
    $message = $this->error->toArray();

    if (App::hasDebugModeEnabled()) {
        Arr::set(
            $message,
            'details.debug',
            [
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'trace' => $this->sanitizeTrace($this->getTraceAsString()),
            ],
        );
    }

    return $this->sanitizeSensitiveData(array_filter($message));
}

/**
 * Remove sensitive data from error details before client transmission.
 */
private function sanitizeSensitiveData(array $data): array
{
    $sensitiveKeys = ['password', 'token', 'secret', 'api_key', 'credit_card'];

    return $this->recursiveMask($data, $sensitiveKeys);
}

/**
 * Recursively mask sensitive fields in nested arrays.
 */
private function recursiveMask(array $data, array $keys): array
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = $this->recursiveMask($value, $keys);
        } elseif (in_array(strtolower($key), $keys, true)) {
            $data[$key] = '[REDACTED]';
        }
    }

    return $data;
}

/**
 * Sanitize stack trace to remove potential sensitive arguments.
 */
private function sanitizeTrace(string $trace): string
{
    // Remove function arguments that might contain sensitive data
    return preg_replace(
        '/\(([^)]*(?:password|token|secret)[^)]*)\)/i',
        '([REDACTED])',
        $trace
    ) ?? $trace;
}
```

---

## Performance Analysis

### 🟢 Object Creation

**Constructor Efficiency**:
```php
public function __construct(
    public readonly ErrorData $error,
) {
    parent::__construct(
        $this->getErrorMessage(),
    );
}
```

✅ **OPTIMAL**: Constructor property promotion eliminates redundant property assignment. Readonly modifier prevents accidental mutation.

### 🟢 Method Complexity

All getter methods are O(1) property access:
```php
public function getErrorCode(): string
{
    return $this->error->code;
}
```

✅ **EXCELLENT**: No loops, no heavy computation, just simple delegation.

### 🟡 Array Serialization

**Potential Concern**:
```php
public function toArray(): array
{
    $message = $this->error->toArray();

    if (App::hasDebugModeEnabled()) {
        Arr::set($message, 'details.debug', [...]);
    }

    return array_filter($message);
}
```

The `array_filter()` call iterates the entire array. For deeply nested error details, this could be wasteful.

**Optimization Opportunity**:

```php
public function toArray(): array
{
    $message = $this->error->toArray();

    if (App::hasDebugModeEnabled()) {
        // Only set debug info if it's not already present
        if (!isset($message['details']['debug'])) {
            $message['details']['debug'] = [
                'file' => $this->getFile(),
                'line' => $this->getLine(),
                'trace' => $this->getTraceAsString(),
            ];
        }
    }

    // Only filter if necessary (most error data won't have null values)
    // array_filter without callback removes null, false, 0, '', []
    // Consider if this is actually needed or if explicit null checks are better
    return $message;
}
```

**Question**: Is `array_filter()` actually necessary? If `ErrorData::toArray()` already omits null values, this is redundant work.

---

## Maintainability Assessment

### 🟢 Extensibility

The protected `new()` factory method enables clean subclass creation:

```php
// Example concrete exception usage:
final class ResourceNotFoundException extends AbstractRequestException
{
    public static function create(string $resourceType, string $id): self
    {
        return self::new(
            code: ErrorCode::RESOURCE_NOT_FOUND,
            message: "Resource '{$resourceType}' with ID '{$id}' not found",
            details: [
                'resource_type' => $resourceType,
                'resource_id' => $id,
            ],
        );
    }
}
```

✅ **EXCELLENT**: Template method pattern makes adding new exception types trivial.

### 🟢 Testability

The class is highly testable due to:
1. Dependency injection via constructor
2. Protected factory method for test doubles
3. No static dependencies (except Laravel facades)
4. Pure methods (deterministic outputs)

**Test Example**:
```php
use PHPUnit\Framework\TestCase;

final class AbstractRequestExceptionTest extends TestCase
{
    public function test_converts_to_array_without_debug_info_in_production(): void
    {
        // Arrange
        App::shouldReceive('hasDebugModeEnabled')->andReturn(false);

        $error = new ErrorData(
            code: ErrorCode::INVALID_REQUEST,
            message: 'Test error',
            details: ['foo' => 'bar'],
        );

        $exception = new ConcreteTestException($error);

        // Act
        $result = $exception->toArray();

        // Assert
        $this->assertArrayNotHasKey('debug', $result['details'] ?? []);
    }

    public function test_includes_debug_info_when_enabled(): void
    {
        // Arrange
        App::shouldReceive('hasDebugModeEnabled')->andReturn(true);

        $error = new ErrorData(
            code: ErrorCode::INTERNAL_ERROR,
            message: 'Debug test',
        );

        $exception = new ConcreteTestException($error);

        // Act
        $result = $exception->toArray();

        // Assert
        $this->assertArrayHasKey('debug', $result['details']);
        $this->assertArrayHasKey('file', $result['details']['debug']);
        $this->assertArrayHasKey('line', $result['details']['debug']);
        $this->assertArrayHasKey('trace', $result['details']['debug']);
    }
}
```

---

## Best Practices Compliance

### ✅ PSR-12 Compliance

- Strict types declaration ✅
- Proper namespace structure ✅
- Visibility modifiers on all properties/methods ✅
- Return type declarations ✅
- Consistent indentation ✅

### ✅ SOLID Principles

1. **Single Responsibility**: ✅ Only handles error representation
2. **Open/Closed**: ✅ Open for extension (subclassing), closed for modification
3. **Liskov Substitution**: ✅ All subclasses can substitute the base
4. **Interface Segregation**: ✅ `RpcException` interface is focused
5. **Dependency Inversion**: ✅ Laravel facade usage is appropriate for Laravel-specific package

### ✅ PHP 8+ Features

- Constructor property promotion ✅
- Readonly properties ✅
- Named arguments in factory ✅
- Null-safe operator ✅
- Null coalescing operator ✅

---

## Critical Issues

**None Found** ✅

---

## Major Issues

**None Found** ✅

---

## Minor Issues

### 🟡 Issue 1: Unexplained PHPStan Suppression

**Location**: Line 199
**Impact**: Future maintainers may not understand why suppression is needed
**Solution**: Already provided in "Error Handling" section - add comprehensive comment explaining the Late Static Binding limitation

### 🟡 Issue 2: Potentially Unnecessary array_filter()

**Location**: Line 173
**Impact**: Minor performance overhead
**Investigation Needed**:

Check if `ErrorData::toArray()` already handles null omission:

```bash
# Run this command to check ErrorData implementation:
rg -A 20 "class ErrorData" /Users/brian/Developer/cline/forrst/src/Data/ErrorData.php
```

If `ErrorData::toArray()` already filters null values, remove line 173:

```php
// Before:
return array_filter($message);

// After:
return $message;
```

---

## Suggestions

### 🔵 Suggestion 1: Add Retry-After Header Support

The `getHeaders()` method returns an empty array, but retryable exceptions should include retry timing:

```php
/**
 * Get HTTP headers to include in the error response.
 *
 * @return array<string, string> Array of HTTP headers
 */
public function getHeaders(): array
{
    $headers = [];

    if ($this->isRetryable() && isset($this->error->details['retry_after'])) {
        $headers['Retry-After'] = (string) $this->error->details['retry_after'];
    }

    return $headers;
}
```

### 🔵 Suggestion 2: Add Method to Get User-Facing Message

Currently, `getErrorMessage()` returns the technical message. Consider adding:

```php
/**
 * Get user-friendly error message safe for display.
 *
 * By default returns the error message, but can be overridden
 * to provide sanitized, translated, or simplified messaging.
 */
public function getUserMessage(): string
{
    return $this->getErrorMessage();
}
```

### 🔵 Suggestion 3: Add Logging Context Method

To facilitate structured logging:

```php
/**
 * Get contextual data for logging.
 *
 * @return array<string, mixed>
 */
public function getLogContext(): array
{
    return [
        'error_code' => $this->getErrorCode(),
        'error_message' => $this->getErrorMessage(),
        'file' => $this->getFile(),
        'line' => $this->getLine(),
        'retryable' => $this->isRetryable(),
        'source' => $this->error->source?->toArray(),
        'details' => $this->error->details,
    ];
}
```

Usage:
```php
Log::error('Forrst exception occurred', $exception->getLogContext());
```

---

## Testing Recommendations

### Unit Tests Required

1. **Constructor and Property Access**
   - Verify readonly properties are immutable
   - Confirm parent constructor receives correct message

2. **Error Code Extraction**
   - Test valid ErrorCode enum values
   - Test edge cases with custom error codes

3. **Retryability Logic**
   - Test all retryable error codes return true
   - Test all permanent error codes return false
   - Test null/invalid error codes default to false

4. **Array Serialization**
   - Test debug mode enabled includes file/line/trace
   - Test debug mode disabled excludes debug info
   - Test null values are filtered correctly
   - Test nested detail structures

5. **Factory Method**
   - Test all parameter combinations
   - Verify ErrorData construction
   - Test null optional parameters

### Integration Tests Required

1. **HTTP Response Integration**
   - Verify status codes match error types
   - Confirm headers are properly set
   - Test JSON serialization of toArray() output

2. **Laravel Debug Mode Integration**
   - Test with APP_DEBUG=true
   - Test with APP_DEBUG=false
   - Verify environment variable respected

### Example Test Suite Structure

```php
<?php

namespace Tests\Unit\Exceptions;

use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Exceptions\AbstractRequestException;
use Tests\TestCase;

final class AbstractRequestExceptionTest extends TestCase
{
    /** @test */
    public function it_constructs_with_error_data(): void
    {
        $errorData = new ErrorData(
            code: ErrorCode::INVALID_REQUEST,
            message: 'Test message',
        );

        $exception = new TestException($errorData);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame($errorData, $exception->toError());
    }

    /** @test */
    public function it_identifies_retryable_errors(): void
    {
        $retryableException = TestException::new(
            code: ErrorCode::TOO_MANY_REQUESTS,
            message: 'Rate limited',
        );

        $this->assertTrue($retryableException->isRetryable());

        $permanentException = TestException::new(
            code: ErrorCode::INVALID_REQUEST,
            message: 'Bad request',
        );

        $this->assertFalse($permanentException->isRetryable());
    }

    /** @test */
    public function it_includes_debug_info_when_enabled(): void
    {
        config(['app.debug' => true]);

        $exception = TestException::new(
            code: ErrorCode::INTERNAL_ERROR,
            message: 'Internal error',
        );

        $array = $exception->toArray();

        $this->assertArrayHasKey('details', $array);
        $this->assertArrayHasKey('debug', $array['details']);
        $this->assertArrayHasKey('file', $array['details']['debug']);
    }
}

// Concrete test double
final class TestException extends AbstractRequestException {}
```

---

## Documentation Quality

**Current**: ⭐⭐⭐⭐⭐ (5/5) - Exceptional

The documentation is comprehensive, accurate, and provides both high-level architectural context and detailed parameter descriptions. The class-level DocBlock effectively communicates the role within the larger system.

**Enhancement**: Add usage examples in DocBlock:

```php
/**
 * Base exception class for Forrst request errors.
 *
 * Part of the Forrst protocol exception hierarchy. Provides the foundation for all
 * Forrst error handling by encapsulating error data and standardized error response
 * formatting. All Forrst exceptions extend this class to ensure consistent error
 * structure across the RPC server implementation.
 *
 * Exceptions are structured around the Forrst error specification with support for
 * error codes, messages, source locations, and additional details. The class handles
 * conversion between exception objects and error response arrays, automatic HTTP
 * status code mapping, and debug information injection when enabled.
 *
 * @example Creating a custom exception
 * ```php
 * final class CustomException extends AbstractRequestException
 * {
 *     public static function invalidInput(string $field): self
 *     {
 *         return self::new(
 *             code: ErrorCode::INVALID_REQUEST,
 *             message: "Invalid input for field: {$field}",
 *             source: new SourceData(parameter: $field),
 *             details: ['field' => $field],
 *         );
 *     }
 * }
 *
 * throw CustomException::invalidInput('email');
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/errors
 */
```

---

## Summary

`AbstractRequestException` is an exemplary piece of foundational architecture that successfully balances protocol compliance, extensibility, and developer experience. The implementation demonstrates strong engineering discipline with comprehensive type safety, clear documentation, and thoughtful design patterns.

### Key Strengths
1. ✅ Excellent SOLID principle adherence
2. ✅ Comprehensive type safety with PHP 8+ features
3. ✅ Secure debug information handling
4. ✅ Clean separation between protocol structure and exception behavior
5. ✅ Extensive documentation at all levels

### Improvement Opportunities
1. 🟡 Document PHPStan suppression rationale
2. 🟡 Investigate necessity of array_filter() call
3. 🔵 Consider sensitive data sanitization
4. 🔵 Add structured logging context helper

### Recommended Actions

**Priority 1 (Before Production)**:
- None - code is production-ready as-is

**Priority 2 (Next Sprint)**:
1. Add explanatory comment for PHPStan suppression
2. Verify `array_filter()` necessity

**Priority 3 (Future Enhancement)**:
1. Add sensitive data sanitization
2. Implement `getLogContext()` method
3. Add retry header support in `getHeaders()`

---

**Reviewer**: Senior Code Review Architect
**Date**: 2025-12-23
**Recommendation**: ✅ **APPROVE** - Merge with confidence, address Priority 2 items in follow-up

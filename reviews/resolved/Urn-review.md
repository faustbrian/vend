# Code Review: Urn.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/Urn.php`
- **Purpose**: URN builder and parser for Forrst identifiers (extensions and functions)
- **Type**: Utility Class / Static Factory

## SOLID Principles Adherence

### ✅ Single Responsibility: EXCELLENT
Focused on URN creation, parsing, and validation only.

### ✅ Open/Closed: GOOD
Closed for modification with `final` class. Extension via vendor parameter.

## Code Quality Analysis

### Documentation Quality: 🟢 EXCELLENT
Clear documentation with format examples and usage patterns.

### Critical Issues

#### 🟠 Major: Regex Performance with Untrusted Input

**Issue**: The `isValid()` and `parse()` methods use regex without input length validation. Malicious input could cause ReDoS (Regular Expression Denial of Service).

**Location**: Lines 113, 123, 143

**Impact**: HIGH - Could cause CPU exhaustion with crafted URN strings

**Solution**: Add input length validation before regex:

```php
// In /Users/brian/Developer/cline/forrst/src/Urn.php

public static function parse(string $urn): array
{
    // Prevent ReDoS attacks
    if (strlen($urn) > 255) {
        throw InvalidUrnFormatException::create($urn);
    }

    // Extension-provided function: urn:vendor:forrst:ext:extension-name:fn:function-name
    if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:ext:([a-z][a-z0-9-]*):fn:(.+)$/', $urn, $matches)) {
        return [
            'vendor' => $matches[1],
            'type' => self::TYPE_FUNCTION,
            'extension' => $matches[2],
            'name' => $matches[3],
        ];
    }

    // Standard URN: urn:vendor:forrst:type:name
    if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:(ext|fn):(.+)$/', $urn, $matches)) {
        return [
            'vendor' => $matches[1],
            'type' => $matches[2],
            'name' => $matches[3],
        ];
    }

    throw InvalidUrnFormatException::create($urn);
}

public static function isValid(string $urn): bool
{
    // Prevent ReDoS attacks
    if (strlen($urn) > 255) {
        return false;
    }

    return (bool) preg_match('/^urn:[a-z][a-z0-9-]*:forrst:(ext|fn)(:[a-z][a-z0-9-]*)+$/', $urn);
}

public static function isCore(string $urn): bool
{
    // Add length check for consistency
    if (strlen($urn) > 255) {
        return false;
    }

    return str_contains($urn, 'urn:'.self::VENDOR.':');
}
```

#### 🟡 Medium: No Validation of Name Format

**Issue**: The `function()` and `extension()` methods don't validate the name parameter format.

**Location**: Lines 59-62, 73-76

**Impact**: MEDIUM - Invalid characters in names could create malformed URNs

**Solution**: Add name validation:

```php
public static function extension(string $name, ?string $vendor = null): string
{
    self::validateName($name, 'extension');
    return self::build(self::TYPE_EXTENSION, $name, $vendor);
}

public static function function(string $name, ?string $vendor = null): string
{
    self::validateName($name, 'function');
    return self::build(self::TYPE_FUNCTION, $name, $vendor);
}

public static function extensionFunction(string $extension, string $functionName, ?string $vendor = null): string
{
    self::validateName($extension, 'extension');
    self::validateName($functionName, 'function');

    $vendor ??= self::VENDOR;

    return implode(':', [
        'urn',
        $vendor,
        self::PROTOCOL,
        self::TYPE_EXTENSION,
        $extension,
        self::TYPE_FUNCTION,
        $functionName,
    ]);
}

/**
 * Validate name format for URN components.
 *
 * @param string $name Name to validate
 * @param string $type Type of component (for error message)
 *
 * @throws \InvalidArgumentException If name format is invalid
 */
private static function validateName(string $name, string $type): void
{
    if (empty($name)) {
        throw new \InvalidArgumentException(
            sprintf('%s name cannot be empty', ucfirst($type))
        );
    }

    if (strlen($name) > 100) {
        throw new \InvalidArgumentException(
            sprintf('%s name cannot exceed 100 characters', ucfirst($type))
        );
    }

    // Allow alphanumeric, hyphens, and colons (for hierarchical names)
    if (!preg_match('/^[a-z][a-z0-9:-]*$/', $name)) {
        throw new \InvalidArgumentException(
            sprintf(
                '%s name "%s" is invalid. Must start with a letter and contain only lowercase letters, numbers, hyphens, and colons',
                ucfirst($type),
                $name
            )
        );
    }
}
```

#### 🟡 Medium: Vendor Parameter Not Validated

**Issue**: The vendor parameter isn't validated for format compliance.

**Location**: Lines 59, 73, 87, 169

**Impact**: MEDIUM - Invalid vendor strings create malformed URNs

**Solution**: Add vendor validation:

```php
/**
 * Validate vendor format.
 *
 * @param string $vendor Vendor identifier
 *
 * @throws \InvalidArgumentException If vendor format is invalid
 */
private static function validateVendor(string $vendor): void
{
    if (empty($vendor)) {
        throw new \InvalidArgumentException('Vendor cannot be empty');
    }

    if (strlen($vendor) > 50) {
        throw new \InvalidArgumentException('Vendor cannot exceed 50 characters');
    }

    if (!preg_match('/^[a-z][a-z0-9-]*$/', $vendor)) {
        throw new \InvalidArgumentException(
            sprintf(
                'Vendor "%s" is invalid. Must start with a letter and contain only lowercase letters, numbers, and hyphens',
                $vendor
            )
        );
    }
}

private static function build(string $type, string $name, ?string $vendor = null): string
{
    $vendor ??= self::VENDOR;
    self::validateVendor($vendor);

    return implode(':', ['urn', $vendor, self::PROTOCOL, $type, $name]);
}
```

### Performance Concerns

#### 🔵 Low: Multiple Regex Calls in parse()

**Issue**: The `parse()` method tests multiple regex patterns sequentially.

**Location**: Lines 113, 123

**Impact**: LOW - Minor performance impact for invalid URNs

**Optimization**: Combine patterns or return early:

```php
public static function parse(string $urn): array
{
    if (strlen($urn) > 255) {
        throw InvalidUrnFormatException::create($urn);
    }

    // Try extension-provided function first (more specific pattern)
    if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:ext:([a-z][a-z0-9-]*):fn:(.+)$/', $urn, $matches)) {
        return [
            'vendor' => $matches[1],
            'type' => self::TYPE_FUNCTION,
            'extension' => $matches[2],
            'name' => $matches[3],
        ];
    }

    // Try standard URN
    if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:(ext|fn):(.+)$/', $urn, $matches)) {
        return [
            'vendor' => $matches[1],
            'type' => $matches[2],
            'name' => $matches[3],
        ];
    }

    // Neither pattern matched
    throw InvalidUrnFormatException::create($urn);
}
```

### Missing Functionality

#### 🔵 Suggestion: Add Caching for Frequently Parsed URNs

**Enhancement**: Add static cache for parse results:

```php
final class Urn
{
    private static array $parseCache = [];
    private const MAX_CACHE_SIZE = 1000;

    public static function parse(string $urn): array
    {
        // Check cache first
        if (isset(self::$parseCache[$urn])) {
            return self::$parseCache[$urn];
        }

        if (strlen($urn) > 255) {
            throw InvalidUrnFormatException::create($urn);
        }

        // ... existing parse logic ...

        $result = [ /* parsed result */ ];

        // Cache the result
        if (count(self::$parseCache) < self::MAX_CACHE_SIZE) {
            self::$parseCache[$urn] = $result;
        }

        return $result;
    }

    /**
     * Clear the parse cache (useful for testing).
     */
    public static function clearCache(): void
    {
        self::$parseCache = [];
    }
}
```

#### 🔵 Suggestion: Add URN Comparison Methods

**Enhancement**: Add utility methods for URN comparison:

```php
/**
 * Check if two URNs are equivalent.
 *
 * @param string $urn1 First URN
 * @param string $urn2 Second URN
 *
 * @return bool True if URNs are equivalent
 */
public static function equals(string $urn1, string $urn2): bool
{
    return $urn1 === $urn2;
}

/**
 * Check if a URN matches a vendor.
 *
 * @param string $urn URN to check
 * @param string $vendor Vendor to match
 *
 * @return bool True if URN belongs to vendor
 */
public static function hasVendor(string $urn, string $vendor): bool
{
    try {
        $parsed = self::parse($urn);
        return $parsed['vendor'] === $vendor;
    } catch (InvalidUrnFormatException) {
        return false;
    }
}

/**
 * Get the vendor from a URN.
 *
 * @param string $urn URN to parse
 *
 * @return string Vendor identifier
 *
 * @throws InvalidUrnFormatException If URN is invalid
 */
public static function getVendor(string $urn): string
{
    return self::parse($urn)['vendor'];
}
```

## Recommendations Summary

### 🟠 High Priority (Security)

1. **Add ReDoS Protection**: Implement input length validation before regex operations (code provided above). **This is critical for production.**

```php
// Add to ALL regex-using methods:
if (strlen($urn) > 255) {
    throw InvalidUrnFormatException::create($urn);
    // or return false for isValid()
}
```

2. **Validate Name Format**: Add validation for name parameters in factory methods (code provided above).

3. **Validate Vendor Format**: Add validation for vendor parameters (code provided above).

### 🟡 Medium Priority

4. **Add Unit Tests**: Ensure comprehensive tests for:
   - Valid URN formats
   - Invalid URN formats
   - Edge cases (empty strings, very long strings, special characters)
   - ReDoS attack vectors

```php
test('parse rejects overly long URNs', function () {
    $longUrn = 'urn:vendor:forrst:fn:' . str_repeat('a', 300);

    expect(fn() => Urn::parse($longUrn))
        ->toThrow(InvalidUrnFormatException::class);
});

test('isValid handles malicious regex patterns', function () {
    $malicious = 'urn:' . str_repeat('a:', 1000) . 'forrst:fn:test';

    $start = microtime(true);
    $result = Urn::isValid($malicious);
    $duration = microtime(true) - $start;

    expect($result)->toBeFalse();
    expect($duration)->toBeLessThan(0.1); // Should complete in <100ms
});
```

### 🔵 Low Priority

5. **Add Caching**: Implement static cache for frequently parsed URNs to improve performance.

6. **Add Utility Methods**: Add `equals()`, `hasVendor()`, `getVendor()` methods for convenience.

## Overall Assessment

**Quality Rating**: 🟡 GOOD with Security Concerns (7.0/10)

**Strengths**:
- Clean static factory pattern
- Good separation of concerns
- Comprehensive parsing logic
- Helpful validation methods

**Critical Security Issue**:
- ReDoS vulnerability in regex methods

**Recommendation**: ⚠️ **REQUIRES FIXES BEFORE PRODUCTION**

The class is well-designed but has a critical security vulnerability. The ReDoS protection MUST be implemented before production use. Add input validation for all user-provided parameters. After these fixes, the class will be production-ready.

**Required Changes**:
1. Add length validation before ALL regex operations
2. Validate name and vendor parameters in factory methods
3. Add comprehensive security-focused unit tests

**Estimated Effort**: 2-4 hours to implement all high-priority fixes and tests.

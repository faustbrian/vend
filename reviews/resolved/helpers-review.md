# Code Review: helpers.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/helpers.php`
- **Purpose**: Helper function for testing Forrst requests in Pest tests
- **Type**: Helper Functions

## SOLID Principles: N/A
Helper functions are procedural by nature.

## Code Quality

### Documentation: 🟢 EXCELLENT
Comprehensive PHPDoc with usage examples, parameter descriptions, and return types.

### Function Guard: ✅ CORRECT
Proper guards prevent redefinition (lines 21-22).

## Critical Issues

### 🟠 Major: Hardcoded ULID in Production Code

**Issue**: Default request ID is a hardcoded ULID that will be reused across all tests.

**Location**: Line 70

**Current Code**:
```php
'id' => $id ?? '01J34641TE5SF58ZX3N9HPT1BA',
```

**Impact**: HIGH - Tests using default ID are not isolated and could have race conditions

**Problem**: Multiple tests using the same request ID could:
- Conflict if server tracks requests by ID
- Make debugging harder (can't distinguish requests)
- Violate protocol requirements for unique IDs

**Solution**: Generate unique ULID for each request:

```php
// In /Users/brian/Developer/cline/forrst/src/helpers.php, line 60-78

if (!function_exists('post_forrst') && function_exists('Pest\Laravel\postJson')) {
    function post_forrst(
        string $function,
        ?array $arguments = null,
        ?string $version = null,
        ?string $id = null,
    ): TestResponse {
        // Generate unique ULID if not provided
        if ($id === null) {
            if (class_exists(\Symfony\Component\Uid\Ulid::class)) {
                $id = (string) \Symfony\Component\Uid\Ulid::generate();
            } elseif (function_exists('str')) {
                $id = (string) str()->ulid();
            } else {
                // Fallback to UUID if ULID not available
                $id = (string) \Illuminate\Support\Str::uuid();
            }
        }

        return postJson(
            route('rpc'),
            array_filter([
                'protocol' => ProtocolData::forrst()->toArray(),
                'id' => $id,
                'call' => array_filter([
                    'function' => $function,
                    'version' => $version,
                    'arguments' => $arguments,
                ]),
            ]),
        );
    }
}
```

### 🟡 Medium: Route Name Hardcoded

**Issue**: Helper assumes route is named 'rpc', but ServerInterface allows custom route names.

**Location**: Line 67

**Current Code**:
```php
route('rpc'),
```

**Impact**: MEDIUM - Tests will fail if server uses different route name

**Solution**: Add route parameter with default:

```php
/**
 * @param string                    $function  The function URN to invoke
 * @param null|array<string, mixed> $arguments Optional arguments
 * @param null|string               $version   Optional semantic version
 * @param null|string               $id        Optional request ID (auto-generated if null)
 * @param string                    $routeName Optional route name (default: 'rpc')
 *
 * @return TestResponse<Response>
 */
function post_forrst(
    string $function,
    ?array $arguments = null,
    ?string $version = null,
    ?string $id = null,
    string $routeName = 'rpc',
): TestResponse {
    // Generate unique ID if not provided...

    return postJson(
        route($routeName),
        array_filter([
            'protocol' => ProtocolData::forrst()->toArray(),
            'id' => $id,
            'call' => array_filter([
                'function' => $function,
                'version' => $version,
                'arguments' => $arguments,
            ]),
        ]),
    );
}
```

### 🔵 Low: No Validation of Function URN

**Issue**: Helper doesn't validate that $function is a valid URN format.

**Enhancement**: Add validation:

```php
function post_forrst(
    string $function,
    ?array $arguments = null,
    ?string $version = null,
    ?string $id = null,
    string $routeName = 'rpc',
): TestResponse {
    // Validate function URN format
    if (!str_starts_with($function, 'urn:')) {
        throw new \InvalidArgumentException(
            "Function must be a URN (e.g., 'urn:vendor:forrst:fn:name'), got: {$function}"
        );
    }

    // Generate ID...
    // Make request...
}
```

### 🔵 Low: Protocol Version Not Configurable

**Issue**: Helper always uses `ProtocolData::forrst()` without allowing custom protocol versions.

**Enhancement**: Add protocol parameter:

```php
function post_forrst(
    string $function,
    ?array $arguments = null,
    ?string $version = null,
    ?string $id = null,
    string $routeName = 'rpc',
    ?ProtocolData $protocol = null,
): TestResponse {
    return postJson(
        route($routeName),
        array_filter([
            'protocol' => ($protocol ?? ProtocolData::forrst())->toArray(),
            'id' => $id ?? generateUniqueId(),
            'call' => array_filter([
                'function' => $function,
                'version' => $version,
                'arguments' => $arguments,
            ]),
        ]),
    );
}
```

## Security Considerations

### 🔵 Low: Test Helper in Production Code

**Issue**: This file is in `src/` directory, meaning it's loaded in production even though it's only useful for testing.

**Impact**: LOW - Minimal overhead but pollutes production namespace

**Recommendation**: Move to `tests/Helpers/` or use composer.json dev autoload:

```json
{
    "autoload-dev": {
        "files": [
            "tests/Helpers/pest_helpers.php"
        ]
    }
}
```

## Testing Recommendations

```php
// Test the helper itself
test('post_forrst generates unique IDs', function () {
    $response1 = post_forrst('urn:test:forrst:fn:ping');
    $response2 = post_forrst('urn:test:forrst:fn:ping');

    $request1 = json_decode($response1->getContent(), true);
    $request2 = json_decode($response2->getContent(), true);

    expect($request1['id'])->not->toBe($request2['id']);
});

test('post_forrst accepts custom ID', function () {
    $customId = 'custom-test-id-123';
    $response = post_forrst('urn:test:forrst:fn:ping', id: $customId);

    $request = json_decode($response->getContent(), true);

    expect($request['id'])->toBe($customId);
});

test('post_forrst validates URN format', function () {
    expect(fn() => post_forrst('invalid-function-name'))
        ->toThrow(\InvalidArgumentException::class, 'must be a URN');
});
```

## Recommendations Summary

### 🟠 High Priority (Breaking Behavior)

1. **Generate Unique IDs**: Replace hardcoded ULID with unique generation for each request (code provided above).

```php
// Add before the helper function
if (!function_exists('generateUniqueForrstId')) {
    function generateUniqueForrstId(): string
    {
        if (class_exists(\Symfony\Component\Uid\Ulid::class)) {
            return (string) \Symfony\Component\Uid\Ulid::generate();
        }
        if (function_exists('str')) {
            return (string) str()->ulid();
        }
        return (string) \Illuminate\Support\Str::uuid();
    }
}

// Use in post_forrst
'id' => $id ?? generateUniqueForrstId(),
```

### 🟡 Medium Priority

2. **Add Route Name Parameter**: Allow custom route names (code provided above).

3. **Add URN Validation**: Validate function parameter is a valid URN.

### 🔵 Low Priority

4. **Move to Dev Dependencies**: Consider moving to tests/ directory or dev autoload.

5. **Add Protocol Parameter**: Allow custom protocol versions for testing.

## Overall Assessment

**Quality Rating**: 🟡 GOOD with Critical Issue (7.0/10)

**Strengths**:
- Excellent documentation with examples
- Proper conditional loading
- Clean, focused helper
- Good abstraction over test complexity

**Critical Issue**:
- Hardcoded request ID breaks test isolation

**Recommendation**: ⚠️ **REQUIRES FIX BEFORE WIDESPREAD USE**

The hardcoded ULID must be replaced with unique ID generation. This is critical for test isolation and protocol compliance. After this fix, the helper is production-ready for testing purposes.

**Estimated Effort**: 1-2 hours to implement unique ID generation and add tests.

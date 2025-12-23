# Code Review: ForceJson.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Http/Middleware/ForceJson.php`
**Purpose:** Enforces JSON content type for Forrst RPC endpoints by automatically setting Content-Type and Accept headers.

## Executive Summary
Simple, focused middleware with clear purpose. Effective implementation but lacks configurability and has hardcoded path matching.

**Severity Breakdown:**
- Critical: 0
- Major: 0
- Minor: 2
- Suggestions: 3

---

## SOLID Principles: 9/10
Good adherence. Single responsibility, simple implementation. Minor deduction for hardcoded paths.

---

## Code Quality Issues

### 🟡 MINOR Issue #1: Hardcoded Path Matching
**Location:** Line 44
**Impact:** Cannot customize which paths get JSON enforcement without modifying middleware.

**Problem:**
```php
if ($request->is('rpc') || $request->is('rpc/*')) {
```

Hardcoded to `/rpc` and `/rpc/*` paths. Applications with different route structures must fork middleware.

**Solution:** Make paths configurable:
```php
final class ForceJson
{
    /**
     * Get the path patterns that should enforce JSON.
     *
     * @return array<int, string>
     */
    protected function getJsonPaths(): array
    {
        return config('forrst.json_paths', ['rpc', 'rpc/*']);
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $paths = $this->getJsonPaths();
        
        foreach ($paths as $pattern) {
            if ($request->is($pattern)) {
                $request->headers->set('Content-Type', 'application/json');
                $request->headers->set('Accept', 'application/json');
                break;
            }
        }
        
        return $next($request);
    }
}
```

**Configuration:**
```php
// config/forrst.php
return [
    'json_paths' => [
        'rpc',
        'rpc/*',
        'api/forrst',
        'api/forrst/*',
    ],
];
```

---

### 🟡 MINOR Issue #2: Overwrites Existing Headers
**Location:** Lines 45-46
**Impact:** Silently overwrites client-provided Content-Type/Accept headers without logging.

**Problem:**
```php
$request->headers->set('Content-Type', 'application/json');
$request->headers->set('Accept', 'application/json');
```

If client sends `Content-Type: application/vnd.api+json`, it's overwritten without warning. This may break clients that rely on specific media types.

**Solution:** Only set if not already JSON:
```php
public function handle(Request $request, Closure $next): mixed
{
    if ($request->is('rpc') || $request->is('rpc/*')) {
        // Only override if not already JSON-compatible
        $contentType = $request->header('Content-Type');
        if ($contentType === null || !str_contains($contentType, 'json')) {
            $request->headers->set('Content-Type', 'application/json');
        }
        
        $accept = $request->header('Accept');
        if ($accept === null || !str_contains($accept, 'json')) {
            $request->headers->set('Accept', 'application/json');
        }
    }
    
    return $next($request);
}
```

OR force override but log when overwriting:
```php
public function handle(Request $request, Closure $next): mixed
{
    if ($request->is('rpc') || $request->is('rpc/*')) {
        $originalContentType = $request->header('Content-Type');
        $originalAccept = $request->header('Accept');
        
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');
        
        if (config('forrst.debug') && 
            ($originalContentType !== 'application/json' || 
             $originalAccept !== 'application/json')) {
            \Log::debug('ForceJson: Overrode client headers', [
                'original_content_type' => $originalContentType,
                'original_accept' => $originalAccept,
                'path' => $request->path(),
            ]);
        }
    }
    
    return $next($request);
}
```

---

## 🔵 SUGGESTIONS

### Suggestion #1: Add Content-Type Validation
**Benefit:** Reject non-JSON requests early with clear error.

```php
public function handle(Request $request, Closure $next): mixed
{
    if ($request->is('rpc') || $request->is('rpc/*')) {
        $contentType = $request->header('Content-Type');
        
        // Strict mode: reject non-JSON requests
        if (config('forrst.strict_content_type', false)) {
            if ($contentType !== null && !str_contains($contentType, 'json')) {
                return response()->json([
                    'protocol' => ['name' => 'forrst', 'version' => '1.0.0'],
                    'id' => null,
                    'errors' => [[
                        'status' => '415',
                        'code' => 'unsupported_media_type',
                        'title' => 'Invalid Content-Type',
                        'detail' => sprintf(
                            'Expected application/json, got %s',
                            $contentType
                        ),
                    ]],
                ], 200); // 200 per Forrst spec even for errors
            }
        }
        
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');
    }
    
    return $next($request);
}
```

---

### Suggestion #2: Support Route-Based Configuration
**Benefit:** Fine-grained control per route.

```php
public function handle(Request $request, Closure $next): mixed
{
    // Check if route has 'force_json' middleware parameter
    $route = $request->route();
    $forceJson = $route?->getAction('force_json') ?? true;
    
    if ($forceJson && ($request->is('rpc') || $request->is('rpc/*'))) {
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');
    }
    
    return $next($request);
}
```

**Route Usage:**
```php
Route::post('/rpc/{server}', FunctionController::class)
    ->middleware(['force_json' => true]);
```

---

### Suggestion #3: Add Charset Enforcement
**Benefit:** Ensure UTF-8 encoding for all Forrst requests.

```php
public function handle(Request $request, Closure $next): mixed
{
    if ($request->is('rpc') || $request->is('rpc/*')) {
        $request->headers->set('Content-Type', 'application/json; charset=utf-8');
        $request->headers->set('Accept', 'application/json; charset=utf-8');
    }
    
    return $next($request);
}
```

---

## Security: ✅ Secure
No security issues. Header manipulation is internal and safe.

## Performance: ✅ Excellent
Minimal overhead. Simple string matching and header setting.

## Testing Recommendations
1. Test with /rpc path
2. Test with /rpc/* paths
3. Test with non-RPC paths (should not modify headers)
4. Test with existing Content-Type header
5. Test with existing Accept header
6. Test header values after middleware

---

## Maintainability: 8/10

**Strengths:** Simple, focused, easy to understand
**Weaknesses:** Hardcoded paths, no configurability

**Priority Actions:**
1. 🟡 Make paths configurable (Minor Issue #1)
2. 🟡 Add header override logging (Minor Issue #2)

**Estimated Time:** 1 hour
**Risk:** Very Low

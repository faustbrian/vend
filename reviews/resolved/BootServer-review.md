# Code Review: BootServer.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Http/Middleware/BootServer.php`
**Purpose:** Bootstraps the Forrst server for each incoming request by resolving and binding the appropriate server instance based on route name.

## Executive Summary
Clean middleware implementation with proper lifecycle management. Good use of dependency injection and container binding. Minor improvements needed for error handling and configuration validation.

**Severity Breakdown:**
- Critical: 0
- Major: 0
- Minor: 2
- Suggestions: 2

---

## SOLID Principles: 10/10
Excellent adherence. Single responsibility (server bootstrapping), dependency injection, proper cleanup.

---

## Code Quality Issues

### 🟡 MINOR Issue #1: Incomplete Null Check Comment
**Location:** Line 74
**Impact:** Comment suggests PHPStan issue but doesn't explain the actual runtime scenario.

**Problem:**
```php
// @phpstan-ignore-next-line - route() can be null when resolver not set, despite PHPStan's conditional return type
$routeName = $request->route()?->getName();

throw_if($routeName === null, RouteNameRequiredException::create());
```

The comment explains PHPStan behavior but not *when* route() actually returns null in production.

**Solution:** Improve documentation:
```php
// Route can be null in several scenarios:
// 1. Request doesn't match any route (should be caught by 404 handler before this)
// 2. Route resolver not set (testing/console contexts)
// 3. Route exists but has no name (misconfiguration)
// @phpstan-ignore-next-line - PHPStan's conditional return type doesn't account for resolver edge cases
$routeName = $request->route()?->getName();

if ($routeName === null) {
    throw RouteNameRequiredException::create();
}
```

---

### 🟡 MINOR Issue #2: Unsafe Container Instance Override
**Location:** Lines 79-82
**Impact:** No validation that server instance is valid before binding.

**Problem:**
```php
$this->container->instance(
    ServerInterface::class,
    $this->serverRepository->findByName($routeName),
);
```

If `findByName()` returns null or invalid instance, container will bind invalid value.

**Solution:** Add validation:
```php
$server = $this->serverRepository->findByName($routeName);

if (!$server instanceof ServerInterface) {
    throw new \RuntimeException(
        sprintf(
            'Server repository returned invalid instance for route "%s". '.
            'Expected %s, got %s',
            $routeName,
            ServerInterface::class,
            get_debug_type($server)
        )
    );
}

$this->container->instance(ServerInterface::class, $server);
```

---

## 🔵 SUGGESTIONS

### Suggestion #1: Add Server Caching
**Benefit:** Avoid repeated server resolution for same route.

```php
final readonly class BootServer
{
    private static array $serverCache = [];

    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        
        throw_if($routeName === null, RouteNameRequiredException::create());
        
        // Check cache first
        if (!isset(self::$serverCache[$routeName])) {
            self::$serverCache[$routeName] = $this->serverRepository->findByName($routeName);
            
            if (!self::$serverCache[$routeName] instanceof ServerInterface) {
                throw new \RuntimeException(
                    sprintf('Invalid server for route "%s"', $routeName)
                );
            }
        }
        
        $this->container->instance(
            ServerInterface::class,
            self::$serverCache[$routeName]
        );
        
        $response = $next($request);
        assert($response instanceof Response);
        
        return $response;
    }
}
```

---

### Suggestion #2: Add Debug Logging
**Benefit:** Better troubleshooting for server resolution issues.

```php
public function handle(Request $request, Closure $next): Response
{
    $routeName = $request->route()?->getName();
    
    if (config('forrst.debug', false)) {
        \Log::debug('BootServer: Resolving server', [
            'route_name' => $routeName,
            'route_exists' => $request->route() !== null,
        ]);
    }
    
    throw_if($routeName === null, RouteNameRequiredException::create());
    
    $server = $this->serverRepository->findByName($routeName);
    
    if (config('forrst.debug', false)) {
        \Log::debug('BootServer: Server resolved', [
            'route_name' => $routeName,
            'server_class' => get_class($server),
        ]);
    }
    
    $this->container->instance(ServerInterface::class, $server);
    
    // ... rest of method
}
```

---

## Security: ✅ Secure
No direct security issues. Server resolution is internal.

## Performance: ✅ Excellent
Minimal overhead. Single repository lookup and container binding.

## Testing Recommendations
1. Test with valid named route
2. Test with unnamed route (should throw)
3. Test with null route
4. Test cleanup in unit test mode
5. Test cleanup skipped in production
6. Test invalid server instance from repository

---

## Maintainability: 9/10

**Strengths:** Clean, focused, good lifecycle management
**Weaknesses:** Limited validation, minimal error context

**Priority Actions:**
1. 🟡 Improve null check documentation (Minor Issue #1)
2. 🟡 Add server instance validation (Minor Issue #2)

**Estimated Time:** 1 hour
**Risk:** Very Low

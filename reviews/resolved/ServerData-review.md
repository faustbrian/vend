# Code Review: ServerData.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Data/Configuration/ServerData.php`

**Purpose:** Configuration data for a single Forrst server instance including routing, versioning, middleware, and function registry.

---

## SOLID Principles: EXCELLENT (9/10)
Clean separation of concerns, properly final, good immutability.

---

## Code Quality Issues

### 1. CRITICAL: Missing Factory Method
**Severity:** CRITICAL

**Location:** Entire class

**Solution:**
```php
public static function createFromArray(array $data): self
{
    return new self(
        name: $data['name'] ?? throw new \InvalidArgumentException('Server name is required'),
        path: $data['path'] ?? throw new \InvalidArgumentException('Server path is required'),
        route: $data['route'] ?? throw new \InvalidArgumentException('Server route is required'),
        version: $data['version'] ?? '1.0.0',
        middleware: $data['middleware'] ?? [],
        functions: $data['functions'] ?? null,
    );
}
```

### 2. MAJOR: No Route Validation
**Severity:** MAJOR

**Location:** Lines 41-42

**Solution:**
```php
private static function validateRoute(string $route): void
{
    if (!str_starts_with($route, '/')) {
        throw new \InvalidArgumentException(
            sprintf('Route "%s" must start with "/"', $route)
        );
    }

    if (!preg_match('#^/[\w\-/{}\*]+$#', $route)) {
        throw new \InvalidArgumentException(
            sprintf('Invalid route format: "%s"', $route)
        );
    }
}

public function __construct(
    public readonly string $name,
    public readonly string $path,
    public readonly string $route,
    public readonly string $version,
    public readonly array $middleware,
    public readonly ?array $functions,
) {
    self::validateRoute($this->route);
    self::validateVersion($this->version);
    self::validateMiddleware($this->middleware);
}
```

### 3. MAJOR: No Middleware Class Validation
**Severity:** MAJOR

**Location:** Line 58

**Solution:**
```php
private static function validateMiddleware(array $middleware): void
{
    foreach ($middleware as $index => $middlewareClass) {
        if (!is_string($middlewareClass)) {
            throw new \InvalidArgumentException(
                sprintf('Middleware at index %d must be a string, got %s', $index, get_debug_type($middlewareClass))
            );
        }

        // Validate class exists (in production/testing)
        if (app()->environment(['local', 'testing', 'production'])) {
            if (!class_exists($middlewareClass)) {
                throw new \InvalidArgumentException(
                    sprintf('Middleware class "%s" does not exist', $middlewareClass)
                );
            }
        }
    }
}
```

### 4. MODERATE: Functions Array Type Safety
**Severity:** MODERATE

**Location:** Line 59

**Solution:**
```php
private static function validateFunctions(?array $functions): void
{
    if ($functions === null) {
        return;
    }

    foreach ($functions as $index => $functionClass) {
        if (!is_string($functionClass)) {
            throw new \InvalidArgumentException(
                sprintf('Function at index %d must be a class-string, got %s', $index, get_debug_type($functionClass))
            );
        }

        // Verify implements FunctionInterface
        if (app()->environment(['local', 'testing'])) {
            if (!class_exists($functionClass)) {
                throw new \InvalidArgumentException(
                    sprintf('Function class "%s" does not exist', $functionClass)
                );
            }

            if (!is_subclass_of($functionClass, FunctionInterface::class)) {
                throw new \InvalidArgumentException(
                    sprintf('Function class "%s" must implement FunctionInterface', $functionClass)
                );
            }
        }
    }
}
```

### 5. MINOR: Missing Helper Methods
**Severity:** MINOR

**Solution:**
```php
public function hasMiddleware(): bool
{
    return $this->middleware !== [];
}

public function hasExplicitFunctions(): bool
{
    return $this->functions !== null;
}

public function usesAutoDiscovery(): bool
{
    return $this->functions === null;
}

public function getMiddlewareCount(): int
{
    return count($this->middleware);
}

public function hasMiddlewareClass(string $middlewareClass): bool
{
    return in_array($middlewareClass, $this->middleware, true);
}
```

---

## Security Vulnerabilities

### 1. Path Injection Risk
**Severity:** HIGH

**Location:** Line 38

**Solution:**
```php
private static function validatePath(string $path): void
{
    // Prevent directory traversal
    if (str_contains($path, '..')) {
        throw new \InvalidArgumentException(
            sprintf('Path traversal detected in path: "%s"', $path)
        );
    }

    // Ensure absolute path or valid namespace
    if (!str_starts_with($path, '/') && !str_starts_with($path, 'App\\')) {
        throw new \InvalidArgumentException(
            sprintf('Path must be absolute or valid namespace: "%s"', $path)
        );
    }

    // If filesystem path, verify it exists and is within app
    if (str_starts_with($path, '/')) {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new \InvalidArgumentException(
                sprintf('Path does not exist: "%s"', $path)
            );
        }

        $appPath = base_path();
        if (!str_starts_with($realPath, $appPath)) {
            throw new \InvalidArgumentException(
                sprintf('Path is outside application root: "%s"', $path)
            );
        }
    }
}
```

---

## Summary

**Overall Quality: GOOD (7/10)**

### Critical Issues
- Missing `createFromArray()` factory method

### Major Issues
- No route format validation
- No middleware class validation
- No function class validation
- Path injection vulnerability

### Recommended Actions
1. Add factory method: `createFromArray()`
2. Implement comprehensive validation in constructor
3. Add path traversal protection
4. Validate middleware classes exist and are valid
5. Validate function classes implement FunctionInterface
6. Add helper methods for common operations

**Files Complete:** 3 of 16

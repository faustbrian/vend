# Code Review: ConfigurationData.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Data/Configuration/ConfigurationData.php`

**Purpose:** Main configuration data container for the Forrst package, holding namespace mappings, file paths, resource definitions, and server configurations.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP): EXCELLENT
Focused solely on configuration data structure. No business logic or processing.

### Open/Closed Principle (OCP): GOOD
Properly marked as final. Configuration structure is fixed but extensible through the arrays.

### Liskov Substitution Principle (LSP): EXCELLENT
Correct extension of AbstractData.

### Interface Segregation Principle (ISP): EXCELLENT
Clean, minimal interface.

### Dependency Inversion Principle (DIP): GOOD
Depends on Spatie's DataCollection abstraction.

---

## Code Quality Issues

### 1. CRITICAL: Missing Factory Method (Violates Codebase Standard)
**Severity:** CRITICAL

**Issue:** No `createFrom*` factory method as required by codebase standards.

**Location:** Entire class

**Impact:** Violates established factory method naming convention, incompatible with Valinor constructor patterns.

**Solution:**
```php
// Add to ConfigurationData.php (after line 64):

/**
 * Create configuration from array data.
 *
 * @param array<string, mixed> $data Configuration array
 * @return self Configured instance
 */
public static function createFromArray(array $data): self
{
    return new self(
        namespaces: $data['namespaces'] ?? [],
        paths: $data['paths'] ?? [],
        resources: $data['resources'] ?? [],
        servers: DataCollection::create(
            ServerData::class,
            $data['servers'] ?? []
        ),
    );
}

/**
 * Create configuration from config file.
 *
 * @param string $configKey The config key (e.g., 'rpc')
 * @return self Configured instance
 */
public static function createFromConfig(string $configKey = 'rpc'): self
{
    $config = config($configKey, []);
    return self::createFromArray($config);
}
```

### 2. MAJOR: No Validation of Required Fields
**Severity:** MAJOR

**Issue:** Arrays can be empty or contain invalid data without validation.

**Location:** Lines 57-64

**Impact:** Invalid configurations could cause runtime failures later.

**Solution:**
```php
public function __construct(
    public readonly array $namespaces,
    public readonly array $paths,
    #[Present()]
    public readonly array $resources,
    #[DataCollectionOf(ServerData::class)]
    public readonly DataCollection $servers,
) {
    $this->validate();
}

private function validate(): void
{
    // Validate namespaces
    if ($this->namespaces === []) {
        throw new \InvalidArgumentException('Namespaces configuration cannot be empty');
    }

    foreach ($this->namespaces as $key => $namespace) {
        if (!is_string($key) || !is_string($namespace)) {
            throw new \InvalidArgumentException('Namespace mappings must be string => string');
        }

        if (!class_exists($namespace) && !str_starts_with($namespace, 'App\\')) {
            throw new \InvalidArgumentException(
                sprintf('Invalid namespace "%s" for key "%s"', $namespace, $key)
            );
        }
    }

    // Validate paths
    if ($this->paths === []) {
        throw new \InvalidArgumentException('Paths configuration cannot be empty');
    }

    foreach ($this->paths as $key => $path) {
        if (!is_string($key) || !is_string($path)) {
            throw new \InvalidArgumentException('Path mappings must be string => string');
        }
    }

    // Validate servers
    if ($this->servers->isEmpty()) {
        throw new \InvalidArgumentException('At least one server must be configured');
    }
}
```

### 3. MODERATE: Unused Resources Array
**Severity:** MODERATE

**Issue:** Documentation states resources array is "Currently unused but reserved for future".

**Location:** Lines 47-50, Line 61

**Impact:** Dead code, unclear purpose, adds complexity without value.

**Solution:**
```php
// Either remove if truly unused:
public function __construct(
    public readonly array $namespaces,
    public readonly array $paths,
    // Remove resources parameter
    #[DataCollectionOf(ServerData::class)]
    public readonly DataCollection $servers,
) {}

// OR mark as deprecated if keeping for future:
/**
 * @param array<string, mixed> $resources Resource transformation configuration
 *                                        @deprecated Reserved for future use
 */
public function __construct(
    public readonly array $namespaces,
    public readonly array $paths,
    #[Deprecated('Reserved for future resource mapping features')]
    #[Present()]
    public readonly array $resources,
    #[DataCollectionOf(ServerData::class)]
    public readonly DataCollection $servers,
) {}
```

### 4. MINOR: Missing Helper Methods
**Severity:** MINOR

**Issue:** No convenience methods for accessing configuration data.

**Location:** Entire class

**Impact:** Consumers need to access arrays directly.

**Solution:**
```php
/**
 * Get a namespace by key.
 */
public function getNamespace(string $key): ?string
{
    return $this->namespaces[$key] ?? null;
}

/**
 * Get a path by key.
 */
public function getPath(string $key): ?string
{
    return $this->paths[$key] ?? null;
}

/**
 * Get a server by name.
 */
public function getServer(string $name): ?ServerData
{
    return $this->servers->first(fn(ServerData $server) => $server->name === $name);
}

/**
 * Check if a namespace key exists.
 */
public function hasNamespace(string $key): bool
{
    return isset($this->namespaces[$key]);
}

/**
 * Get all server names.
 */
public function getServerNames(): array
{
    return $this->servers->map(fn(ServerData $s) => $s->name)->toArray();
}
```

---

## Security Vulnerabilities

### 1. Path Traversal Risk
**Severity:** MODERATE

**Issue:** Path values not validated, could contain `../` sequences.

**Location:** Lines 42-46

**Impact:** Potential directory traversal if paths are used to load files.

**Solution:**
```php
private function validatePaths(): void
{
    foreach ($this->paths as $key => $path) {
        // Normalize path
        $realPath = realpath($path);

        if ($realPath === false) {
            throw new \InvalidArgumentException(
                sprintf('Path "%s" for key "%s" does not exist', $path, $key)
            );
        }

        // Check for traversal attempts
        if (str_contains($path, '..')) {
            throw new \InvalidArgumentException(
                sprintf('Path traversal detected in path "%s" for key "%s"', $path, $key)
            );
        }

        // Ensure path is within application root
        $appPath = base_path();
        if (!str_starts_with($realPath, $appPath)) {
            throw new \InvalidArgumentException(
                sprintf('Path "%s" is outside application root for key "%s"', $path, $key)
            );
        }
    }
}
```

---

## Performance Concerns

### 1. DataCollection Creation Overhead
**Severity:** MINOR

**Issue:** DataCollection is created on every instantiation without caching.

**Location:** Line 63

**Impact:** Minor performance hit if configuration is loaded repeatedly.

**Solution:**
```php
// Cache at application level:
// In a service provider:
public function register(): void
{
    $this->app->singleton(ConfigurationData::class, function ($app) {
        return ConfigurationData::createFromConfig('rpc');
    });
}
```

---

## Summary

**Overall Code Quality: GOOD (7/10)**

### Critical Issues
1. Missing `createFrom*` factory methods (violates codebase standard)

### Major Issues
1. No validation of configuration data
2. Unused resources array adds complexity

### Recommended Actions
1. Add `createFromArray()` and `createFromConfig()` factory methods
2. Implement comprehensive validation in constructor
3. Remove or deprecate unused resources array
4. Add path traversal protection
5. Add helper methods for accessing configuration
6. Implement singleton caching pattern

### Positive Recognition
Clean DTO design with proper use of Spatie's DataCollection. Good documentation explaining each configuration component.

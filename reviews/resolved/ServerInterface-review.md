# Code Review: ServerInterface.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/Contracts/ServerInterface.php`
- **Purpose**: Contract for Forrst server instances managing RPC endpoints
- **Type**: Interface

## SOLID Principles: ✅ EXCELLENT
Single responsibility (server configuration), excellent abstraction.

## Critical Issues

### 🟡 Medium: No Validation Contract

**Issue**: No method to validate server configuration at registration time.

**Enhancement**: Add validation method:

```php
/**
 * Validate server configuration.
 *
 * Called during server registration to ensure proper setup.
 *
 * @throws \InvalidArgumentException If configuration is invalid
 */
public function validate(): void;
```

Implementation:
```php
public function validate(): void
{
    if (empty($this->getName())) {
        throw new \InvalidArgumentException('Server name cannot be empty');
    }

    if (!str_starts_with($this->getRoutePath(), '/')) {
        throw new \InvalidArgumentException('Route path must start with /');
    }

    if (empty($this->getRouteName())) {
        throw new \InvalidArgumentException('Route name cannot be empty');
    }

    if (!preg_match('/^\d+\.\d+\.\d+$/', $this->getVersion())) {
        throw new \InvalidArgumentException('Version must be semantic (e.g., 1.0.0)');
    }
}
```

### 🔵 Low: Missing Health Check Method

**Enhancement**: Add health check capability:

```php
/**
 * Get health checkers registered for this server.
 *
 * @return array<int, HealthCheckerInterface>
 */
public function getHealthCheckers(): array;
```

## Quality Rating: 🟢 EXCELLENT (8.8/10)

**Recommendation**: ✅ **APPROVED** - Consider adding validation method for early error detection.

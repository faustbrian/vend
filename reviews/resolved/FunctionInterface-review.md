# Code Review: FunctionInterface.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/Contracts/FunctionInterface.php`
- **Purpose**: Core contract defining RPC function handlers with complete discovery metadata, argument validation, and result specifications
- **Type**: Interface / Contract
- **Complexity**: High - 15 methods covering metadata, arguments, results, errors, and lifecycle

## SOLID Principles Adherence

### 🟡 Single Responsibility Principle (SRP)
**Status**: MODERATE VIOLATION

**Issue**: The interface combines multiple responsibilities:
1. **Identity**: `getUrn()`, `getVersion()`
2. **Documentation**: `getSummary()`, `getDescription()`, `getTags()`, `getExamples()`, `getLinks()`, `getExternalDocs()`
3. **Schema Definition**: `getArguments()`, `getResult()`, `getErrors()`
4. **Query Capabilities**: `getQuery()`
5. **Discovery Control**: `isDiscoverable()`
6. **Extension Management**: `getExtensions()`
7. **Deprecation**: `getDeprecated()`
8. **Side Effects**: `getSideEffects()`
9. **Simulation**: `getSimulations()`
10. **Request Handling**: `setRequest()`

**Location**: Lines 41-207 (entire interface)

**Impact**: MEDIUM - This is a "fat interface" that forces implementations to provide many methods even if they don't use all features

**Solution - Interface Segregation**:
```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Contracts;

/**
 * Core function identity and schema contract.
 */
interface FunctionInterface
{
    public function getUrn(): string;
    public function getVersion(): string;
    public function getSummary(): string;
    public function getArguments(): array;
    public function getResult(): ?ResultDescriptorData;
    public function getErrors(): array;
    public function setRequest(RequestObjectData $requestObject): void;
}

/**
 * Extended documentation capabilities for functions.
 */
interface DocumentableFunctionInterface extends FunctionInterface
{
    public function getDescription(): ?string;
    public function getTags(): ?array;
    public function getExamples(): ?array;
    public function getLinks(): ?array;
    public function getExternalDocs(): ?ExternalDocsData;
}

/**
 * Query capabilities for list/collection functions.
 */
interface QueryableFunctionInterface extends FunctionInterface
{
    public function getQuery(): ?QueryCapabilitiesData;
}

/**
 * Lifecycle and extension management.
 */
interface ExtensibleFunctionInterface extends FunctionInterface
{
    public function getDeprecated(): ?DeprecatedData;
    public function getSideEffects(): ?array;
    public function isDiscoverable(): bool;
    public function getExtensions(): ?FunctionExtensionsData;
    public function getSimulations(): ?array;
}

/**
 * Complete function contract with all capabilities.
 */
interface FullFunctionInterface extends
    FunctionInterface,
    DocumentableFunctionInterface,
    QueryableFunctionInterface,
    ExtensibleFunctionInterface
{
    // Composite interface
}
```

**Counter-argument (Current Design Justification)**:
The current design may be intentional to ensure a consistent discovery response format across all functions. Having optional methods (returning `null`) is simpler than managing multiple interface combinations.

**Recommendation**: If breaking changes are acceptable, segregate the interface. Otherwise, document the design decision and provide an abstract base class that implements optional methods with null returns:

```php
// In /Users/brian/Developer/cline/forrst/src/Functions/AbstractFunction.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Functions;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Data\RequestObjectData;

abstract class AbstractFunction implements FunctionInterface
{
    protected RequestObjectData $request;

    // Required: subclasses must implement
    abstract public function getUrn(): string;
    abstract public function getVersion(): string;
    abstract public function getSummary(): string;
    abstract public function getArguments(): array;
    abstract public function getResult(): ?ResultDescriptorData;
    abstract public function getErrors(): array;

    // Optional: default implementations
    public function getDescription(): ?string { return null; }
    public function getTags(): ?array { return null; }
    public function getQuery(): ?QueryCapabilitiesData { return null; }
    public function getDeprecated(): ?DeprecatedData { return null; }
    public function getSideEffects(): ?array { return null; }
    public function isDiscoverable(): bool { return true; }
    public function getExamples(): ?array { return null; }
    public function getLinks(): ?array { return null; }
    public function getExternalDocs(): ?ExternalDocsData { return null; }
    public function getSimulations(): ?array { return null; }
    public function getExtensions(): ?FunctionExtensionsData { return null; }

    public function setRequest(RequestObjectData $requestObject): void
    {
        $this->request = $requestObject;
    }
}
```

### ✅ Open/Closed Principle (OCP)
**Status**: EXCELLENT

The interface is open for extension (new implementations) and closed for modification. Good use of optional returns (`?Type`) allows evolution without breaking changes.

### ✅ Liskov Substitution Principle (LSP)
**Status**: EXCELLENT

All implementations must satisfy the contract. The extensive documentation ensures implementers understand expectations.

### 🟡 Interface Segregation Principle (ISP)
**Status**: VIOLATION

**Issue**: Clients must depend on methods they don't use. A simple read-only function still needs to implement `getSideEffects()`, `getSimulations()`, `getExtensions()`, etc.

**Rating**: As discussed in SRP section, this is a fat interface that should be segregated.

### ✅ Dependency Inversion Principle (DIP)
**Status**: EXCELLENT

Methods depend on data objects and abstractions (`ResultDescriptorData`, `ArgumentData`, etc.) rather than concrete implementations.

## Code Quality Analysis

### Documentation Quality
**Rating**: 🟢 EXCELLENT

The documentation is comprehensive and well-structured:
- Clear interface-level description
- Every method documented with purpose and return types
- Specific format requirements (e.g., semantic versioning)
- Examples in comments (lines 49, 62)
- Multiple `@see` references

### Type Safety

#### 🟡 Medium: Inconsistent Array Return Types

**Issue**: Some methods return mixed array/object types without strict enforcement.

**Location**: Lines 84-86, 104-106, 120-122, 164-166, 170-172, 186-188

**Current Code**:
```php
public function getArguments(): array; // Array of ArgumentData|array<string, mixed>
public function getErrors(): array;   // Array of ErrorDefinitionData|array<string, mixed>
public function getTags(): ?array;    // Array of TagData|array<string, mixed>
```

**Impact**: MEDIUM - Implementations could return plain arrays instead of typed objects, reducing type safety

**Solution**: Enforce specific types in documentation and validation:
```php
/**
 * Get argument definitions for this function.
 *
 * MUST return an array of ArgumentData objects. Plain arrays are deprecated
 * and will be removed in version 2.0.
 *
 * @return array<int, ArgumentData> Array of argument descriptors
 *
 * @throws \InvalidArgumentException If any element is not an ArgumentData instance
 */
public function getArguments(): array;
```

Add runtime validation:
```php
// In function repository or validator
public function validateFunction(FunctionInterface $function): void
{
    foreach ($function->getArguments() as $index => $argument) {
        if (!$argument instanceof ArgumentData) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Argument at index %d must be an instance of ArgumentData, %s given',
                    $index,
                    get_debug_type($argument)
                )
            );
        }
    }

    // Similar validation for getErrors(), getTags(), etc.
}
```

#### 🔵 Low: URN Format Not Validated

**Issue**: `getUrn()` returns a string but doesn't enforce URN format.

**Location**: Line 53

**Solution**: Add documentation and consider validation:
```php
/**
 * Get the URN (Uniform Resource Name) for this function.
 *
 * The URN uniquely identifies this function across the Forrst ecosystem.
 * URNs follow the format: urn:<vendor>:forrst:fn:<function-name>
 *
 * MUST use Urn::function() helper to ensure proper format.
 *
 * Example: urn:acme:forrst:fn:orders:create
 *
 * @example
 * ```php
 * public function getUrn(): string
 * {
 *     return Urn::function('orders:create', 'acme');
 * }
 * ```
 *
 * @return string Function URN identifier in proper format
 */
public function getUrn(): string;
```

#### 🔵 Low: Semantic Versioning Not Enforced

**Issue**: `getVersion()` requires semantic versioning but doesn't enforce it.

**Location**: Line 64

**Solution**: Use a value object:
```php
// Create /Users/brian/Developer/cline/forrst/src/Data/SemanticVersion.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Data;

final readonly class SemanticVersion
{
    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $prerelease = null,
    ) {
        if ($major < 0 || $minor < 0 || $patch < 0) {
            throw new \InvalidArgumentException('Version numbers must be non-negative');
        }

        if ($prerelease !== null && !preg_match('/^[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*$/', $prerelease)) {
            throw new \InvalidArgumentException('Invalid prerelease identifier');
        }
    }

    public static function parse(string $version): self
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/', $version, $matches)) {
            throw new \InvalidArgumentException("Invalid semantic version: {$version}");
        }

        return new self(
            major: (int) $matches[1],
            minor: (int) $matches[2],
            patch: (int) $matches[3],
            prerelease: $matches[4] ?? null,
        );
    }

    public function toString(): string
    {
        $version = "{$this->major}.{$this->minor}.{$this->patch}";
        if ($this->prerelease !== null) {
            $version .= "-{$this->prerelease}";
        }
        return $version;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
```

Update interface:
```php
/**
 * Get the function version.
 *
 * @return SemanticVersion Function version object
 */
public function getVersion(): SemanticVersion;
```

## Security Analysis

### 🔵 Low: Request Object Injection

**Issue**: `setRequest()` accepts any `RequestObjectData` without validation.

**Location**: Line 206

**Impact**: LOW - Assumes calling code validates requests before injection

**Current Code**:
```php
public function setRequest(RequestObjectData $requestObject): void;
```

**Enhancement**: Document expectations:
```php
/**
 * Inject the current request object into the function handler.
 *
 * Called by the dispatcher before function execution to provide access
 * to the full request context, including arguments, ID, and metadata.
 *
 * SECURITY: The request object is assumed to be validated before injection.
 * Implementations should NOT re-validate the request structure but MAY
 * perform business logic validation on arguments.
 *
 * @param RequestObjectData $requestObject The validated incoming Forrst request
 */
public function setRequest(RequestObjectData $requestObject): void;
```

### 🟢 Positive: Side Effects Declaration

**Strength**: The `getSideEffects()` method (line 148) explicitly declares mutations, which is excellent for security auditing and idempotency checks.

## Performance Considerations

### 🟡 Moderate: Repeated Method Calls

**Issue**: Discovery/documentation methods may be called multiple times during request processing, caching, or reflection.

**Impact**: MEDIUM - Implementations that build complex objects on each call will waste resources

**Solution**: Document caching expectations:
```php
/**
 * Get argument definitions for this function.
 *
 * PERFORMANCE: This method may be called multiple times during function
 * registration and request processing. Implementations SHOULD cache the
 * result in a private constant or property.
 *
 * @example
 * ```php
 * private const ARGUMENTS = [
 *     ArgumentData::make('user_id')->type('string')->required(),
 *     ArgumentData::make('limit')->type('integer')->optional(),
 * ];
 *
 * public function getArguments(): array
 * {
 *     return self::ARGUMENTS;
 * }
 * ```
 *
 * @return array<int, ArgumentData> Array of argument descriptors
 */
public function getArguments(): array;
```

## Maintainability Assessment

### Testability
**Rating**: 🟡 MODERATE

**Issue**: Testing implementations requires satisfying 15 method signatures even for simple functions.

**Solution**: Provide testing helpers:
```php
// In /Users/brian/Developer/cline/forrst/tests/Helpers/FunctionTestDouble.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Tests\Helpers;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Data\RequestObjectData;

/**
 * Minimal test double for FunctionInterface with builder pattern.
 */
final class FunctionTestDouble implements FunctionInterface
{
    private string $urn = 'urn:test:forrst:fn:test';
    private string $version = '1.0.0';
    private string $summary = 'Test function';
    private array $arguments = [];
    private ?ResultDescriptorData $result = null;
    private array $errors = [];

    public static function make(): self
    {
        return new self();
    }

    public function withUrn(string $urn): self
    {
        $this->urn = $urn;
        return $this;
    }

    // ... builder methods for all properties ...

    public function getUrn(): string { return $this->urn; }
    public function getVersion(): string { return $this->version; }
    public function getSummary(): string { return $this->summary; }
    public function getArguments(): array { return $this->arguments; }
    public function getResult(): ?ResultDescriptorData { return $this->result; }
    public function getErrors(): array { return $this->errors; }
    public function getDescription(): ?string { return null; }
    public function getTags(): ?array { return null; }
    public function getQuery(): ?QueryCapabilitiesData { return null; }
    public function getDeprecated(): ?DeprecatedData { return null; }
    public function getSideEffects(): ?array { return null; }
    public function isDiscoverable(): bool { return true; }
    public function getExamples(): ?array { return null; }
    public function getLinks(): ?array { return null; }
    public function getExternalDocs(): ?ExternalDocsData { return null; }
    public function getSimulations(): ?array { return null; }
    public function getExtensions(): ?FunctionExtensionsData { return null; }
    public function setRequest(RequestObjectData $requestObject): void {}
}
```

### Change Impact
**Rating**: 🟠 HIGH RISK

Adding or removing methods from this interface is a breaking change affecting all implementations. The interface is stable but inflexible.

**Mitigation**: Use default interface methods (PHP 8.1+) for future additions:
```php
/**
 * Get function timeout in seconds.
 *
 * @return int Timeout in seconds (default: 30)
 */
public function getTimeout(): int
{
    return 30;
}
```

## Architectural Considerations

### Design Pattern: Metadata Provider
**Rating**: 🟢 EXCELLENT

The interface implements the Metadata Provider pattern, separating function metadata from execution logic. This is appropriate for a discovery-based RPC system.

### Coupling to Discovery Specification
**Rating**: 🟡 MODERATE CONCERN

**Issue**: The interface is tightly coupled to the Forrst Discovery specification. Changes to the spec require interface changes.

**Mitigation**: Version the interface and provide migration paths:
```php
/**
 * @version 1.0 Forrst Discovery Specification v1
 * @see https://docs.cline.sh/forrst/discovery/v1
 */
interface FunctionInterface { }

/**
 * @version 2.0 Forrst Discovery Specification v2
 * @see https://docs.cline.sh/forrst/discovery/v2
 */
interface FunctionInterfaceV2 extends FunctionInterface { }
```

## Missing Functionality

### 🔵 Suggestion: Execution Contract Missing

**Issue**: The interface defines metadata but not execution behavior.

**Current Approach**: Execution is likely handled by `__invoke()` or `handle()` in implementations, but this isn't specified in the interface.

**Enhancement**: Consider adding execution contract:
```php
interface ExecutableFunctionInterface extends FunctionInterface
{
    /**
     * Execute the function with validated arguments.
     *
     * @param array<string, mixed> $arguments Validated arguments
     *
     * @return mixed Function result
     *
     * @throws FunctionException On execution failure
     */
    public function execute(array $arguments): mixed;
}
```

Or keep execution separate and document the pattern:
```php
/**
 * Forrst function contract interface.
 *
 * This interface defines function METADATA only. Execution is handled
 * by implementations via __invoke() or handle() methods, which are
 * resolved dynamically by the function dispatcher.
 *
 * Implementations MUST provide one of:
 * - public function __invoke(...): mixed
 * - public function handle(...): mixed
 */
```

### 🔵 Suggestion: Validation Hook Missing

**Enhancement**: Add optional validation hook:
```php
/**
 * Validate arguments before execution.
 *
 * Provides business logic validation beyond schema validation.
 * Called after schema validation, before execution.
 *
 * @param array<string, mixed> $arguments Validated arguments
 *
 * @throws \InvalidArgumentException On validation failure
 */
public function validateArguments(array $arguments): void
{
    // Default: no additional validation
}
```

## Recommendations Summary

### 🟠 High Priority

1. **Provide Abstract Base Class**: Create `AbstractFunction` class implementing optional methods with null defaults to reduce boilerplate in implementations. Location: `/Users/brian/Developer/cline/forrst/src/Functions/AbstractFunction.php` (code provided above)

2. **Add Type Validation**: Implement runtime validation in function repository to ensure `getArguments()`, `getErrors()`, etc. return properly typed objects.

```php
// In /Users/brian/Developer/cline/forrst/src/Repositories/FunctionRepository.php

public function validateFunction(FunctionInterface $function): void
{
    // Validate URN format
    if (!Urn::isValid($function->getUrn())) {
        throw new \InvalidArgumentException(
            sprintf('Invalid function URN: %s', $function->getUrn())
        );
    }

    // Validate semantic version
    if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z-]+)?$/', $function->getVersion())) {
        throw new \InvalidArgumentException(
            sprintf('Invalid semantic version: %s', $function->getVersion())
        );
    }

    // Validate arguments are ArgumentData instances
    foreach ($function->getArguments() as $index => $argument) {
        if (!$argument instanceof ArgumentData && !is_array($argument)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Argument at index %d must be ArgumentData or array, %s given',
                    $index,
                    get_debug_type($argument)
                )
            );
        }
    }

    // Validate errors are ErrorDefinitionData instances
    foreach ($function->getErrors() as $index => $error) {
        if (!$error instanceof ErrorDefinitionData && !is_array($error)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Error at index %d must be ErrorDefinitionData or array, %s given',
                    $index,
                    get_debug_type($error)
                )
            );
        }
    }

    // Validate side effects are valid strings
    if ($function->getSideEffects() !== null) {
        $validSideEffects = ['create', 'update', 'delete'];
        foreach ($function->getSideEffects() as $sideEffect) {
            if (!in_array($sideEffect, $validSideEffects, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Invalid side effect "%s". Must be one of: %s',
                        $sideEffect,
                        implode(', ', $validSideEffects)
                    )
                );
            }
        }
    }
}
```

### 🟡 Medium Priority

3. **Add Performance Documentation**: Document caching expectations for metadata methods.

4. **Create Test Helpers**: Provide `FunctionTestDouble` class for easier testing.

5. **Enforce Type Constraints**: Update documentation to specify that mixed array/object returns are deprecated.

### 🔵 Low Priority

6. **Consider Interface Segregation**: Evaluate breaking the interface into focused contracts for future major version.

7. **Add Execution Contract**: Document or formalize the execution pattern (`__invoke()` vs `handle()`).

8. **Use Value Objects**: Create `SemanticVersion` value object for version strings.

## Overall Assessment

**Quality Rating**: 🟢 GOOD (7.5/10)

**Strengths**:
- Comprehensive coverage of function metadata
- Excellent documentation with examples
- Type-safe data objects for complex structures
- Nullable returns allow optional features
- Clear separation between metadata and execution

**Weaknesses**:
- Violates Interface Segregation Principle (fat interface)
- Mixed array/object return types reduce type safety
- Missing runtime validation for URN format and semantic versioning
- Tightly coupled to discovery specification
- No performance guidance for repeated calls
- Testing implementations requires satisfying many unused methods

**Critical Issues**: None - interface is production-ready

**Recommendation**: ✅ **APPROVED** with enhancements

The interface is well-designed despite being large. Provide an abstract base class to reduce implementation burden, add runtime validation, and document performance expectations. The interface segregation violation is acceptable given the discovery-oriented architecture, but should be revisited in a future major version.

The comprehensive method set ensures consistent discovery responses across all functions, which aligns with the protocol's goals. The trade-off between interface segregation and API consistency is reasonable for this use case.

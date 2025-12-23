# Code Review: CallData.php

**File Path:** `/Users/brian/Developer/cline/forrst/src/Data/CallData.php`

**Purpose:** Represents the call object within a Forrst protocol request, containing the function to invoke, optional version, and arguments for RPC method execution.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP): EXCELLENT
The class has one clear responsibility: encapsulating call data for function invocation. It doesn't handle execution, validation, or routing.

### Open/Closed Principle (OCP): EXCELLENT
The class is a final DTO, which is appropriate. It's open for extension through composition (used within RequestObjectData) but closed for inheritance, which is correct for value objects.

### Liskov Substitution Principle (LSP): EXCELLENT
Properly extends AbstractData without violating any parent class contracts.

### Interface Segregation Principle (ISP): EXCELLENT
No unnecessary interface implementations. Clean, minimal design.

### Dependency Inversion Principle (DIP): EXCELLENT
Depends on the AbstractData abstraction. No concrete dependencies.

---

## Code Quality Issues

### 1. Missing Factory Method (Factory Method Naming Convention)
**Severity:** MAJOR

**Issue:** The class lacks a proper factory method following the `createFrom*` convention recommended in your codebase. The documentation mentions this is a refactoring playbook rule.

**Location:** Entire class (missing factory method)

**Impact:** Inconsistency with codebase standards. Harder to discover how to create instances. Not compatible with Valinor constructor annotations if used.

**Solution:**
```php
// Add to CallData.php:

/**
 * Create a call data instance from an array.
 *
 * @param array<string, mixed> $data The array data containing call information
 * @return self Configured CallData instance
 */
public static function createFromArray(array $data): self
{
    return new self(
        function: $data['function'] ?? throw new \InvalidArgumentException('Function name is required'),
        version: $data['version'] ?? null,
        arguments: isset($data['arguments']) && is_array($data['arguments']) ? $data['arguments'] : null,
    );
}

/**
 * Create a call data instance from a request object.
 *
 * @param string $function The function name
 * @param null|array<string, mixed> $arguments Optional arguments
 * @param null|string $version Optional version
 * @return self Configured CallData instance
 */
public static function createFrom(
    string $function,
    ?array $arguments = null,
    ?string $version = null,
): self {
    return new self(
        function: $function,
        version: $version,
        arguments: $arguments,
    );
}
```

### 2. No Validation of Function Name Format
**Severity:** MAJOR

**Issue:** The documentation mentions function names use dot notation (e.g., "users.create"), but there's no validation to enforce this format.

**Location:** Line 43-44 (constructor)

**Impact:** Invalid function names could propagate through the system, causing runtime errors later in the processing pipeline.

**Solution:**
```php
// Add validation method:
private static function validateFunctionName(string $function): void
{
    // Validate dot notation format
    if (!preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/i', $function)) {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid function name "%s". Function names must use dot notation (e.g., "users.create", "orders.list").',
                $function
            )
        );
    }

    // Validate maximum length
    if (strlen($function) > 255) {
        throw new \InvalidArgumentException(
            sprintf('Function name "%s" exceeds maximum length of 255 characters', $function)
        );
    }
}

// Update constructor:
public function __construct(
    public readonly string $function,
    public readonly ?string $version = null,
    public readonly ?array $arguments = null,
) {
    if (config('forrst.validate_function_names', true)) {
        self::validateFunctionName($function);
    }
}
```

### 3. No Validation of Version Format
**Severity:** MINOR

**Issue:** The documentation mentions versions like "1.0", "2.0", but there's no validation for semantic versioning format.

**Location:** Line 45

**Impact:** Invalid version strings could cause confusion or errors in version matching logic.

**Solution:**
```php
// Add validation for version:
private static function validateVersion(string $version): void
{
    // Validate semantic versioning format
    if (!preg_match('/^\d+\.\d+(\.\d+)?(-[a-z0-9]+)?$/i', $version)) {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid version "%s". Versions must follow semantic versioning format (e.g., "1.0", "2.0.1", "1.0.0-alpha").',
                $version
            )
        );
    }
}

// Update constructor:
public function __construct(
    public readonly string $function,
    public readonly ?string $version = null,
    public readonly ?array $arguments = null,
) {
    if (config('forrst.validate_function_names', true)) {
        self::validateFunctionName($function);
    }

    if ($version !== null && config('forrst.validate_versions', true)) {
        self::validateVersion($version);
    }
}
```

### 4. No Type Validation for Arguments Array
**Severity:** MINOR

**Issue:** The arguments array is typed as `null|array<string, mixed>` but there's no validation that keys are actually strings.

**Location:** Line 46

**Impact:** Numeric or invalid keys could cause issues when arguments are accessed by name.

**Solution:**
```php
// Add argument validation:
private static function validateArguments(array $arguments): void
{
    foreach (array_keys($arguments) as $key) {
        if (!is_string($key)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid argument key type "%s". All argument keys must be strings, got %s.',
                    $key,
                    get_debug_type($key)
                )
            );
        }

        // Optionally validate key format
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $key)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid argument key "%s". Keys must be valid identifiers (letters, numbers, underscores).',
                    $key
                )
            );
        }
    }
}

// Update constructor:
public function __construct(
    public readonly string $function,
    public readonly ?string $version = null,
    public readonly ?array $arguments = null,
) {
    if (config('forrst.validate_function_names', true)) {
        self::validateFunctionName($function);
    }

    if ($version !== null && config('forrst.validate_versions', true)) {
        self::validateVersion($version);
    }

    if ($arguments !== null && config('forrst.validate_arguments', true)) {
        self::validateArguments($arguments);
    }
}
```

### 5. Missing Helper Methods
**Severity:** MINOR

**Issue:** No convenience methods for common operations like checking if arguments exist, getting argument count, etc.

**Location:** Entire class

**Impact:** Consumers need to null-check and array-access arguments directly.

**Solution:**
```php
// Add helper methods:

/**
 * Check if the call has any arguments.
 *
 * @return bool True if arguments are present
 */
public function hasArguments(): bool
{
    return $this->arguments !== null && $this->arguments !== [];
}

/**
 * Get the number of arguments.
 *
 * @return int The argument count
 */
public function getArgumentCount(): int
{
    return $this->arguments !== null ? count($this->arguments) : 0;
}

/**
 * Check if a specific argument exists.
 *
 * @param string $key The argument key
 * @return bool True if the argument exists
 */
public function hasArgument(string $key): bool
{
    return isset($this->arguments[$key]);
}

/**
 * Get a specific argument value with optional default.
 *
 * @param string $key The argument key
 * @param mixed $default The default value if not found
 * @return mixed The argument value or default
 */
public function getArgument(string $key, mixed $default = null): mixed
{
    return $this->arguments[$key] ?? $default;
}

/**
 * Check if a version was specified.
 *
 * @return bool True if version is set
 */
public function hasVersion(): bool
{
    return $this->version !== null;
}

/**
 * Get the function namespace (part before last dot).
 *
 * @return null|string The namespace or null if no namespace
 */
public function getNamespace(): ?string
{
    $lastDotPos = strrpos($this->function, '.');

    if ($lastDotPos === false) {
        return null;
    }

    return substr($this->function, 0, $lastDotPos);
}

/**
 * Get the function method name (part after last dot).
 *
 * @return string The method name
 */
public function getMethodName(): string
{
    $lastDotPos = strrpos($this->function, '.');

    if ($lastDotPos === false) {
        return $this->function;
    }

    return substr($this->function, $lastDotPos + 1);
}
```

---

## Security Vulnerabilities

### 1. Potential Argument Injection
**Severity:** MODERATE

**Issue:** Arguments are accepted without validation or sanitization. Malicious arguments could potentially cause issues if used in unsafe contexts (SQL, shell commands, etc.).

**Location:** Line 46

**Impact:** If arguments are used in unsafe contexts without proper escaping, this could lead to injection vulnerabilities.

**Solution:**
```php
// Add argument sanitization configuration:

// In config/forrst.php:
return [
    'security' => [
        'max_argument_depth' => 10, // Prevent deeply nested attacks
        'max_argument_size' => 1024 * 1024, // 1MB limit
        'forbidden_argument_keys' => ['__proto__', 'constructor', 'prototype'],
        'enable_argument_sanitization' => true,
    ],
];

// Add security validation:
private static function validateArgumentSecurity(array $arguments, int $depth = 0): void
{
    $maxDepth = config('forrst.security.max_argument_depth', 10);

    if ($depth > $maxDepth) {
        throw new \InvalidArgumentException(
            sprintf('Arguments exceed maximum nesting depth of %d', $maxDepth)
        );
    }

    $forbiddenKeys = config('forrst.security.forbidden_argument_keys', []);

    foreach ($arguments as $key => $value) {
        // Check for forbidden keys (prototype pollution prevention)
        if (in_array($key, $forbiddenKeys, true)) {
            throw new \InvalidArgumentException(
                sprintf('Forbidden argument key detected: "%s"', $key)
            );
        }

        // Recursively validate nested arrays
        if (is_array($value)) {
            self::validateArgumentSecurity($value, $depth + 1);
        }
    }

    // Check total size
    $serialized = json_encode($arguments);
    $size = strlen($serialized);
    $maxSize = config('forrst.security.max_argument_size', 1024 * 1024);

    if ($size > $maxSize) {
        throw new \InvalidArgumentException(
            sprintf(
                'Arguments size (%d bytes) exceeds maximum allowed size of %d bytes',
                $size,
                $maxSize
            )
        );
    }
}

// Update constructor to include security validation:
public function __construct(
    public readonly string $function,
    public readonly ?string $version = null,
    public readonly ?array $arguments = null,
) {
    // ... existing validation ...

    if ($arguments !== null && config('forrst.security.enable_argument_sanitization', true)) {
        self::validateArgumentSecurity($arguments);
    }
}
```

### 2. No Rate Limiting or Size Constraints
**Severity:** MODERATE

**Issue:** No protection against excessively large argument payloads that could cause memory exhaustion.

**Location:** Line 46

**Impact:** Denial of service through memory exhaustion if large payloads are accepted.

**Solution:**
Implemented in the security validation above with `max_argument_size` configuration.

---

## Performance Concerns

### 1. No Lazy Loading or Caching
**Severity:** MINOR

**Issue:** If helper methods are added (getNamespace, getMethodName), they would recalculate on every call.

**Impact:** Minor performance hit with repeated calls.

**Solution:**
```php
final class CallData extends AbstractData
{
    // Cache calculated values
    private ?string $cachedNamespace = null;
    private ?string $cachedMethodName = null;
    private bool $namespaceParsed = false;

    public function __construct(
        public readonly string $function,
        public readonly ?string $version = null,
        public readonly ?array $arguments = null,
    ) {
        // ... validation ...
    }

    public function getNamespace(): ?string
    {
        if (!$this->namespaceParsed) {
            $this->parseFunction();
        }

        return $this->cachedNamespace;
    }

    public function getMethodName(): string
    {
        if (!$this->namespaceParsed) {
            $this->parseFunction();
        }

        return $this->cachedMethodName ?? $this->function;
    }

    private function parseFunction(): void
    {
        $lastDotPos = strrpos($this->function, '.');

        if ($lastDotPos !== false) {
            $this->cachedNamespace = substr($this->function, 0, $lastDotPos);
            $this->cachedMethodName = substr($this->function, $lastDotPos + 1);
        }

        $this->namespaceParsed = true;
    }
}
```

---

## Maintainability Assessment

### Strengths
1. Simple, clean DTO design
2. Excellent documentation
3. Immutable design with readonly properties
4. Proper use of final keyword

### Weaknesses
1. No validation of data format/constraints
2. Missing factory methods per codebase standards
3. No helper methods for common operations
4. No security considerations for argument data

### Recommendations

1. **Add Comprehensive Validation Layer:**
Create a dedicated validator class:

```php
// In src/Validators/CallDataValidator.php:
namespace Cline\Forrst\Validators;

final class CallDataValidator
{
    public function validateFunction(string $function): void
    {
        // Centralized validation logic
    }

    public function validateVersion(?string $version): void
    {
        // Version validation
    }

    public function validateArguments(?array $arguments): void
    {
        // Argument validation
    }
}

// Use in CallData constructor:
public function __construct(
    public readonly string $function,
    public readonly ?string $version = null,
    public readonly ?array $arguments = null,
) {
    $validator = app(CallDataValidator::class);
    $validator->validateFunction($this->function);
    $validator->validateVersion($this->version);
    $validator->validateArguments($this->arguments);
}
```

2. **Add Builder Pattern for Complex Construction:**
```php
// In src/Builders/CallDataBuilder.php:
namespace Cline\Forrst\Builders;

use Cline\Forrst\Data\CallData;

final class CallDataBuilder
{
    private string $function;
    private ?string $version = null;
    private array $arguments = [];

    public static function create(string $function): self
    {
        $builder = new self();
        $builder->function = $function;
        return $builder;
    }

    public function withVersion(string $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function withArgument(string $key, mixed $value): self
    {
        $this->arguments[$key] = $value;
        return $this;
    }

    public function withArguments(array $arguments): self
    {
        $this->arguments = array_merge($this->arguments, $arguments);
        return $this;
    }

    public function build(): CallData
    {
        return new CallData(
            function: $this->function,
            version: $this->version,
            arguments: $this->arguments !== [] ? $this->arguments : null,
        );
    }
}

// Usage:
$call = CallDataBuilder::create('users.create')
    ->withVersion('2.0')
    ->withArgument('email', 'user@example.com')
    ->withArgument('name', 'John Doe')
    ->build();
```

3. **Add Type Hints for Common Argument Structures:**
```php
/**
 * Create a call with typed arguments.
 *
 * @template T of object
 * @param string $function The function name
 * @param T $argumentsObject Typed arguments object
 * @param null|string $version Optional version
 * @return self
 */
public static function createFromTyped(
    string $function,
    object $argumentsObject,
    ?string $version = null,
): self {
    $arguments = [];

    foreach ((array) $argumentsObject as $key => $value) {
        $arguments[$key] = $value;
    }

    return new self(
        function: $function,
        version: $version,
        arguments: $arguments,
    );
}
```

---

## Documentation Review

### Strengths
- Excellent documentation explaining purpose and parameters
- Good examples in comments (dot notation, versioning)
- Clear parameter descriptions

### Suggestions

1. **Add Usage Examples:**
```php
/**
 * Represents the call object within a Forrst protocol request.
 *
 * Example usage:
 * ```php
 * // Basic function call
 * $call = new CallData(function: 'users.list');
 *
 * // With version
 * $call = new CallData(function: 'users.create', version: '2.0');
 *
 * // With arguments
 * $call = new CallData(
 *     function: 'orders.create',
 *     arguments: [
 *         'customer_id' => 123,
 *         'items' => [
 *             ['product_id' => 1, 'quantity' => 2],
 *             ['product_id' => 2, 'quantity' => 1],
 *         ],
 *     ],
 * );
 * ```
 *
 * @see https://docs.cline.sh/forrst/protocol
 */
```

2. **Document Validation Rules:**
```php
/**
 * @param string $function  The function name to invoke using dot notation
 *                          for namespace organization (e.g., "orders.create",
 *                          "users.update"). Must match a registered function
 *                          name in the server's function registry.
 *
 *                          Validation rules:
 *                          - Must match pattern: ^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$
 *                          - Maximum length: 255 characters
 *                          - Case-insensitive
 */
```

---

## Summary

**Overall Code Quality: GOOD (7/10)**

### Strengths
- Clean, simple DTO design
- Excellent documentation
- Proper immutability with readonly properties
- Appropriate use of final keyword

### Critical Issues
None

### Major Issues
1. Missing factory methods per codebase standards (`createFrom*` convention)
2. No validation of function name format despite documentation requirements
3. No security validation for arguments

### Recommended Actions (Priority Order)
1. **CRITICAL:** Add factory methods following `createFrom*` naming convention
2. **HIGH:** Implement function name validation (dot notation format)
3. **HIGH:** Add security validation for arguments (size limits, depth limits, forbidden keys)
4. **MEDIUM:** Add version format validation
5. **MEDIUM:** Implement helper methods (hasArguments, getArgument, getNamespace, etc.)
6. **LOW:** Add caching for parsed function parts
7. **LOW:** Consider builder pattern for complex construction scenarios

### Positive Recognition
This is a well-designed value object that follows immutability principles correctly. The documentation is thorough and helpful. With the addition of validation and factory methods, this would be excellent production-ready code that perfectly follows your codebase standards.

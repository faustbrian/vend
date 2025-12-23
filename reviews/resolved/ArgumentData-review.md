# Code Review: ArgumentData.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Discovery/ArgumentData.php`
**Purpose:** Defines a Data Transfer Object (DTO) for API function argument definitions used in discovery documentation. This class represents a single function parameter with its JSON Schema, validation rules, defaults, and documentation.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) ✅
**Rating: Excellent**

The class has a single, well-defined responsibility: representing function argument metadata. It does not mix concerns and focuses solely on data representation.

### Open/Closed Principle (OCP) ✅
**Rating: Good**

As a `final` class extending `Spatie\LaravelData\Data`, it is closed for modification but open for extension through composition. The `final` keyword prevents inheritance, which is appropriate for DTOs.

### Liskov Substitution Principle (LSP) ✅
**Rating: Good**

The class properly extends `Data` and maintains behavioral compatibility with its parent class.

### Interface Segregation Principle (ISP) ✅
**Rating: Good**

The class does not implement interfaces beyond what's inherited from `Data`, and all properties are cohesive to its purpose.

### Dependency Inversion Principle (DIP) ⚠️
**Rating: Acceptable**

The class depends on the concrete `Spatie\LaravelData\Data` class and `DeprecatedData`. While this is acceptable for DTOs, consider creating interfaces if these dependencies need to be swapped.

---

## Code Quality Issues

### 🟡 Minor Issue: Type Safety for `$schema` Property
**Location:** Line 56
**Issue:** The `$schema` property is typed as `array<string, mixed>`, which allows any value types. This reduces type safety and makes it impossible to validate schema structure at compile time.

**Impact:** Runtime errors could occur if invalid schema structures are passed. IDE autocompletion and static analysis tools cannot provide meaningful assistance.

**Solution:**
Create a dedicated JsonSchemaData DTO to enforce structure:

```php
// Create new file: src/Discovery/JsonSchemaData.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * JSON Schema definition for parameter validation.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class JsonSchemaData extends Data
{
    /**
     * @param string $type JSON Schema type (string, number, boolean, object, array, null)
     * @param null|string $format Optional format specifier (e.g., email, uuid, date-time)
     * @param null|string $pattern Regex pattern for string validation
     * @param null|int $minLength Minimum string length
     * @param null|int $maxLength Maximum string length
     * @param null|float $minimum Minimum numeric value
     * @param null|float $maximum Maximum numeric value
     * @param null|array<string, mixed> $properties Object property definitions
     * @param null|array<int, string> $required Required property names for objects
     * @param null|array<string, mixed> $items Array item schema
     * @param null|array<int, mixed> $enum Allowed values enumeration
     * @param array<string, mixed> $additionalProperties Additional schema properties
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $format = null,
        public readonly ?string $pattern = null,
        public readonly ?int $minLength = null,
        public readonly ?int $maxLength = null,
        public readonly ?float $minimum = null,
        public readonly ?float $maximum = null,
        public readonly ?array $properties = null,
        public readonly ?array $required = null,
        public readonly ?array $items = null,
        public readonly ?array $enum = null,
        public readonly array $additionalProperties = [],
    ) {}
}
```

Then update ArgumentData.php line 56:

```php
// Before:
public readonly array $schema,

// After:
public readonly JsonSchemaData $schema,
```

Update the PHPDoc at line 32-35:

```php
// Before:
* @param array<string, mixed>   $schema      JSON Schema definition describing the parameter's type, format,

// After:
* @param JsonSchemaData         $schema      JSON Schema definition describing the parameter's type, format,
```

### 🟡 Minor Issue: `$default` Property Type Safety
**Location:** Line 60
**Issue:** The `$default` property is typed as `mixed`, which eliminates all type checking. While defaults can be of any type, this property should validate against the schema type.

**Impact:** No compile-time validation that default values match the schema type. This can lead to runtime validation errors.

**Solution:**
Add a validation method to ensure type consistency:

```php
// Add this method after the constructor in ArgumentData.php:

/**
 * Validate that the default value matches the schema type.
 *
 * @throws \InvalidArgumentException if default value doesn't match schema type
 */
public function validateDefault(): void
{
    if ($this->default === null || $this->required) {
        return;
    }

    $schemaType = $this->schema['type'] ?? null;

    if ($schemaType === null) {
        return;
    }

    $actualType = get_debug_type($this->default);

    $typeMap = [
        'string' => ['string'],
        'number' => ['int', 'integer', 'float', 'double'],
        'integer' => ['int', 'integer'],
        'boolean' => ['bool', 'boolean'],
        'array' => ['array'],
        'object' => ['object', 'array'], // Arrays can represent objects in JSON
        'null' => ['null'],
    ];

    if (!isset($typeMap[$schemaType])) {
        throw new \InvalidArgumentException("Unknown schema type: {$schemaType}");
    }

    if (!in_array($actualType, $typeMap[$schemaType], true)) {
        throw new \InvalidArgumentException(
            "Default value type '{$actualType}' does not match schema type '{$schemaType}' for argument '{$this->name}'"
        );
    }
}
```

Then call this in the constructor:

```php
public function __construct(
    public readonly string $name,
    public readonly array $schema,
    public readonly bool $required = false,
    public readonly ?string $summary = null,
    public readonly ?string $description = null,
    public readonly mixed $default = null,
    public readonly ?DeprecatedData $deprecated = null,
    public readonly ?array $examples = null,
) {
    $this->validateDefault();
}
```

### 🟡 Minor Issue: `$examples` Property Lacks Type Definition
**Location:** Line 62
**Issue:** The `$examples` property is typed as `?array<int, mixed>`, which doesn't enforce what valid examples should contain.

**Impact:** Examples could be any value, making it difficult to ensure consistency and quality in documentation.

**Solution:**
Create an ExampleValueData DTO:

```php
// This appears to already exist based on the file list: ExampleData.php
// Update line 62 to use it:

// Before:
public readonly ?array $examples = null,

// After:
/** @var null|array<int, ExampleData> */
public readonly ?array $examples = null,
```

Update the PHPDoc at lines 51-52:

```php
// Before:
* @param null|array<int, mixed> $examples    Array of example values demonstrating valid parameter usage.

// After:
* @param null|array<int, ExampleData> $examples    Array of example values demonstrating valid parameter usage.
```

### 🔵 Suggestion: Add Named Constructor for Common Cases
**Location:** Class-level
**Issue:** Creating ArgumentData instances with all parameters can be verbose for common simple cases.

**Impact:** Reduced developer experience and increased boilerplate in calling code.

**Solution:**
Add static factory methods for common scenarios:

```php
// Add these static methods to ArgumentData.php:

/**
 * Create a required string argument.
 *
 * @param string $name Argument name
 * @param null|string $summary Brief description
 * @param null|string $description Detailed description
 * @return self
 */
public static function requiredString(
    string $name,
    ?string $summary = null,
    ?string $description = null,
): self {
    return new self(
        name: $name,
        schema: ['type' => 'string'],
        required: true,
        summary: $summary,
        description: $description,
    );
}

/**
 * Create an optional string argument with a default value.
 *
 * @param string $name Argument name
 * @param string $default Default value
 * @param null|string $summary Brief description
 * @param null|string $description Detailed description
 * @return self
 */
public static function optionalString(
    string $name,
    string $default,
    ?string $summary = null,
    ?string $description = null,
): self {
    return new self(
        name: $name,
        schema: ['type' => 'string'],
        required: false,
        summary: $summary,
        description: $description,
        default: $default,
    );
}

/**
 * Create a required integer argument.
 *
 * @param string $name Argument name
 * @param null|int $minimum Minimum value
 * @param null|int $maximum Maximum value
 * @param null|string $summary Brief description
 * @param null|string $description Detailed description
 * @return self
 */
public static function requiredInteger(
    string $name,
    ?int $minimum = null,
    ?int $maximum = null,
    ?string $summary = null,
    ?string $description = null,
): self {
    $schema = ['type' => 'integer'];

    if ($minimum !== null) {
        $schema['minimum'] = $minimum;
    }

    if ($maximum !== null) {
        $schema['maximum'] = $maximum;
    }

    return new self(
        name: $name,
        schema: $schema,
        required: true,
        summary: $summary,
        description: $description,
    );
}

/**
 * Create a required boolean argument.
 *
 * @param string $name Argument name
 * @param null|string $summary Brief description
 * @param null|string $description Detailed description
 * @return self
 */
public static function requiredBoolean(
    string $name,
    ?string $summary = null,
    ?string $description = null,
): self {
    return new self(
        name: $name,
        schema: ['type' => 'boolean'],
        required: true,
        summary: $summary,
        description: $description,
    );
}
```

---

## Security Vulnerabilities

### ✅ No Critical Security Issues Found

**Assessment:**
This is a pure data class with readonly properties, which inherently provides immutability and prevents tampering after construction. No direct security vulnerabilities identified.

**Recommendations:**
1. Ensure that when this data is serialized/deserialized (via Spatie Laravel Data), proper input validation occurs
2. Consider sanitizing `$description` and `$summary` fields if they're rendered in HTML contexts without escaping
3. The JSON Schema in `$schema` should be validated against JSON Schema Draft 7 specification to prevent injection attacks through malformed schemas

---

## Performance Concerns

### 🟢 Performance: Good

**Assessment:**
The class is a lightweight DTO with readonly properties. No performance concerns identified.

**Observations:**
- Readonly properties prevent defensive copying
- No complex computations in constructor
- Minimal memory footprint

**Potential Optimization:**
If this class is instantiated thousands of times in a single request, consider implementing object pooling or flyweight pattern for commonly used argument definitions. However, this is premature optimization unless profiling shows this as a bottleneck.

---

## Maintainability Assessment

### Code Readability: Excellent ✅
- Clear, descriptive property names
- Comprehensive PHPDoc comments
- Well-structured class documentation
- Logical parameter ordering in constructor

### Documentation Quality: Excellent ✅
- Detailed PHPDoc for each parameter explaining purpose, format, and constraints
- Class-level documentation clearly states the purpose
- Includes author and reference link

### Testing Considerations

**Recommended Test Cases:**

```php
// tests/Unit/Discovery/ArgumentDataTest.php
<?php declare(strict_types=1);

namespace Tests\Unit\Discovery;

use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\DeprecatedData;
use PHPUnit\Framework\TestCase;

final class ArgumentDataTest extends TestCase
{
    /** @test */
    public function it_creates_argument_with_minimal_required_parameters(): void
    {
        $argument = new ArgumentData(
            name: 'userId',
            schema: ['type' => 'string'],
        );

        $this->assertSame('userId', $argument->name);
        $this->assertSame(['type' => 'string'], $argument->schema);
        $this->assertFalse($argument->required);
        $this->assertNull($argument->summary);
        $this->assertNull($argument->description);
        $this->assertNull($argument->default);
        $this->assertNull($argument->deprecated);
        $this->assertNull($argument->examples);
    }

    /** @test */
    public function it_creates_required_argument_with_all_properties(): void
    {
        $deprecated = new DeprecatedData(
            reason: 'Use userId instead',
            sunset: '2025-12-31',
        );

        $argument = new ArgumentData(
            name: 'user_id',
            schema: ['type' => 'integer', 'minimum' => 1],
            required: true,
            summary: 'User identifier',
            description: 'The unique identifier for the user',
            deprecated: $deprecated,
            examples: [1, 42, 999],
        );

        $this->assertSame('user_id', $argument->name);
        $this->assertTrue($argument->required);
        $this->assertSame('User identifier', $argument->summary);
        $this->assertSame('The unique identifier for the user', $argument->description);
        $this->assertSame($deprecated, $argument->deprecated);
        $this->assertSame([1, 42, 999], $argument->examples);
    }

    /** @test */
    public function it_allows_default_value_for_optional_arguments(): void
    {
        $argument = new ArgumentData(
            name: 'pageSize',
            schema: ['type' => 'integer'],
            required: false,
            default: 20,
        );

        $this->assertSame(20, $argument->default);
    }

    /** @test */
    public function it_supports_complex_json_schema(): void
    {
        $argument = new ArgumentData(
            name: 'filters',
            schema: [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                    'createdAfter' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => ['status'],
            ],
        );

        $this->assertArrayHasKey('properties', $argument->schema);
        $this->assertArrayHasKey('required', $argument->schema);
    }

    /** @test */
    public function readonly_properties_are_immutable(): void
    {
        $argument = new ArgumentData(
            name: 'test',
            schema: ['type' => 'string'],
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $argument->name = 'modified'; // @phpstan-ignore-line
    }
}
```

---

## Summary of Recommendations

### Critical (Must Fix) 🔴
None identified.

### Major (Should Fix Soon) 🟠
None identified.

### Minor (Consider Fixing) 🟡
1. **Introduce JsonSchemaData DTO** (Line 56) - Replace `array $schema` with strongly-typed `JsonSchemaData $schema` for better type safety and IDE support
2. **Add default value validation** (Line 60) - Implement `validateDefault()` method to ensure default values match schema types
3. **Type examples property** (Line 62) - Use `array<int, ExampleData>` instead of `array<int, mixed>`

### Suggestions (Optional Improvements) 🔵
1. **Add static factory methods** - Create `requiredString()`, `optionalString()`, `requiredInteger()`, `requiredBoolean()` for common use cases
2. **Add comprehensive unit tests** - Cover edge cases, immutability, and validation logic
3. **Consider schema validation** - Add runtime validation that `$schema` conforms to JSON Schema Draft 7 specification

---

## Conclusion

**Overall Rating: 8.5/10**

ArgumentData.php is a well-designed, clean DTO with excellent documentation and adherence to SOLID principles. The class properly uses readonly properties for immutability and the `final` keyword to prevent inheritance. The primary areas for improvement are type safety enhancements through introduction of dedicated DTOs for complex types like JSON Schema, and adding validation logic to ensure data consistency. The code is maintainable, readable, and follows modern PHP best practices.

The suggested improvements would elevate this class from good to excellent by providing stronger compile-time guarantees and better developer experience through static analysis and IDE autocompletion.

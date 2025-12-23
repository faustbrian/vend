# Code Review: ComponentsData.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Discovery/ComponentsData.php`
**Purpose:** Provides a centralized registry of reusable component definitions for API discovery documentation. Acts as a component library that can be referenced throughout discovery documents using `$ref` notation, promoting consistency and reducing duplication.

---

## SOLID Principles Adherence

### Single Responsibility Principle (SRP) ✅
**Rating: Excellent**

The class has a single, clear responsibility: serving as a container for reusable component definitions. It aggregates related components without mixing business logic or transformation concerns.

### Open/Closed Principle (OCP) ⚠️
**Rating: Moderate**

While marked as `final`, which prevents inheritance, the class is not easily extensible if new component types need to be added. Every new component type requires modifying this class directly, violating OCP.

**Issue Location:** Lines 59-68

**Solution:**
Consider using a more flexible approach with a typed collection:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Reusable component definitions for API discovery documentation.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class ComponentsData extends Data
{
    /**
     * @param ComponentRegistry $registry Registry containing all component types
     */
    public function __construct(
        public readonly ComponentRegistry $registry = new ComponentRegistry(),
    ) {}

    /**
     * Register a component type.
     *
     * @param string $type Component type identifier
     * @param array<string, Data> $components Component definitions
     */
    public function register(string $type, array $components): void
    {
        $this->registry->register($type, $components);
    }

    /**
     * Get components of a specific type.
     *
     * @param string $type Component type identifier
     * @return null|array<string, Data>
     */
    public function get(string $type): ?array
    {
        return $this->registry->get($type);
    }
}
```

However, for a discovery document schema, the current approach may be preferable for clarity and JSON serialization. This is a design trade-off.

### Liskov Substitution Principle (LSP) ✅
**Rating: Good**

Properly extends `Spatie\LaravelData\Data` without violating behavioral contracts.

### Interface Segregation Principle (ISP) ✅
**Rating: Good**

All properties are optional and cohesive. Consumers only need to provide the component types they actually use.

### Dependency Inversion Principle (DIP) ⚠️
**Rating: Moderate**

The class depends on multiple concrete Data classes (`ArgumentData`, `ErrorDefinitionData`, `ExampleData`, etc.). While acceptable for DTOs, this creates tight coupling.

---

## Code Quality Issues

### 🟠 Major Issue: Inconsistent Type Constraints
**Location:** Lines 60, 61, 62, 63, 64, 65, 66, 67

**Issue:** Properties are typed as nullable arrays with PHPDoc annotations for structure, but these aren't enforced at runtime. The type system cannot guarantee that array values match the documented types.

**Impact:**
- Runtime errors if wrong types are inserted
- No IDE autocompletion or static analysis for array contents
- Difficult to validate component references
- Inconsistent data structures can propagate through the system

**Solution:**
Create strongly-typed collection classes for each component type:

```php
// Create new file: src/Discovery/Collections/ComponentCollection.php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery\Collections;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/**
 * Type-safe collection for component definitions.
 *
 * @template TData of Data
 * @extends Collection<string, TData>
 */
final class ComponentCollection extends Collection
{
    /**
     * @param class-string<TData> $dataClass Expected Data class type
     * @param array<string, TData> $items Component definitions indexed by name
     */
    public function __construct(
        private readonly string $dataClass,
        array $items = [],
    ) {
        $this->validateItems($items);
        parent::__construct($items);
    }

    /**
     * Validate that all items are instances of the expected Data class.
     *
     * @param array<string, mixed> $items
     * @throws \InvalidArgumentException
     */
    private function validateItems(array $items): void
    {
        foreach ($items as $key => $item) {
            if (!$item instanceof $this->dataClass) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'All items must be instances of %s, got %s for key "%s"',
                        $this->dataClass,
                        get_debug_type($item),
                        $key
                    )
                );
            }
        }
    }

    /**
     * Add a component to the collection.
     *
     * @param string $name Component identifier
     * @param TData $component Component data
     * @return self
     */
    public function add(string $name, Data $component): self
    {
        if (!$component instanceof $this->dataClass) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Component must be instance of %s, got %s',
                    $this->dataClass,
                    get_debug_type($component)
                )
            );
        }

        $this->items[$name] = $component;

        return $this;
    }

    /**
     * Get a component by name.
     *
     * @param string $name Component identifier
     * @return null|TData
     */
    public function getComponent(string $name): ?Data
    {
        return $this->items[$name] ?? null;
    }
}
```

Then update ComponentsData.php:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use Cline\Forrst\Discovery\Collections\ComponentCollection;
use Cline\Forrst\Discovery\Resource\ResourceData;
use Spatie\LaravelData\Data;

/**
 * Reusable component definitions for API discovery documentation.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/extensions/discovery#components
 */
final class ComponentsData extends Data
{
    /**
     * Create a new components definition instance.
     *
     * @param null|ComponentCollection<ArgumentData> $contentDescriptors Reusable content descriptor definitions
     * @param null|ComponentCollection<ErrorDefinitionData> $errors Reusable error definitions
     * @param null|ComponentCollection<ExampleData> $examples Reusable example value definitions
     * @param null|ComponentCollection<ExamplePairingData> $examplePairings Reusable request-response pairs
     * @param null|ComponentCollection<LinkData> $links Reusable link definitions
     * @param null|ComponentCollection<TagData> $tags Reusable tag definitions
     * @param null|ComponentCollection<ResourceData> $resources Reusable resource definitions
     * @param null|array<string, array<string, mixed>> $schemas Raw JSON Schema definitions (kept as array for flexibility)
     */
    public function __construct(
        public readonly ?ComponentCollection $contentDescriptors = null,
        public readonly ?ComponentCollection $errors = null,
        public readonly ?ComponentCollection $examples = null,
        public readonly ?ComponentCollection $examplePairings = null,
        public readonly ?ComponentCollection $links = null,
        public readonly ?ComponentCollection $tags = null,
        public readonly ?ComponentCollection $resources = null,
        public readonly ?array $schemas = null,
    ) {}
}
```

### 🟡 Minor Issue: No Validation for Component References
**Location:** Class-level

**Issue:** There's no mechanism to validate that `$ref` references in the discovery document actually point to existing components in these collections.

**Impact:** Broken references can exist in the document, leading to runtime errors when clients try to resolve them.

**Solution:**
Add a validation method:

```php
// Add to ComponentsData.php:

/**
 * Validate that a component reference exists.
 *
 * @param string $ref Component reference (e.g., "#/components/schemas/User")
 * @return bool
 */
public function hasReference(string $ref): bool
{
    if (!str_starts_with($ref, '#/components/')) {
        return false;
    }

    $parts = explode('/', substr($ref, 13)); // Remove '#/components/'

    if (count($parts) !== 2) {
        return false;
    }

    [$componentType, $componentName] = $parts;

    return match ($componentType) {
        'schemas' => isset($this->schemas[$componentName]),
        'contentDescriptors' => $this->contentDescriptors?->has($componentName) ?? false,
        'errors' => $this->errors?->has($componentName) ?? false,
        'examples' => $this->examples?->has($componentName) ?? false,
        'examplePairings' => $this->examplePairings?->has($componentName) ?? false,
        'links' => $this->links?->has($componentName) ?? false,
        'tags' => $this->tags?->has($componentName) ?? false,
        'resources' => $this->resources?->has($componentName) ?? false,
        default => false,
    };
}

/**
 * Resolve a component reference to its actual data.
 *
 * @param string $ref Component reference (e.g., "#/components/schemas/User")
 * @return mixed
 * @throws \InvalidArgumentException if reference doesn't exist
 */
public function resolveReference(string $ref): mixed
{
    if (!$this->hasReference($ref)) {
        throw new \InvalidArgumentException("Component reference '{$ref}' does not exist");
    }

    $parts = explode('/', substr($ref, 13));
    [$componentType, $componentName] = $parts;

    return match ($componentType) {
        'schemas' => $this->schemas[$componentName],
        'contentDescriptors' => $this->contentDescriptors->getComponent($componentName),
        'errors' => $this->errors->getComponent($componentName),
        'examples' => $this->examples->getComponent($componentName),
        'examplePairings' => $this->examplePairings->getComponent($componentName),
        'links' => $this->links->getComponent($componentName),
        'tags' => $this->tags->getComponent($componentName),
        'resources' => $this->resources->getComponent($componentName),
    };
}

/**
 * Get all component references in this components registry.
 *
 * @return array<string> Array of all valid component references
 */
public function getAllReferences(): array
{
    $references = [];

    if ($this->schemas !== null) {
        foreach (array_keys($this->schemas) as $name) {
            $references[] = "#/components/schemas/{$name}";
        }
    }

    if ($this->contentDescriptors !== null) {
        foreach ($this->contentDescriptors->keys() as $name) {
            $references[] = "#/components/contentDescriptors/{$name}";
        }
    }

    if ($this->errors !== null) {
        foreach ($this->errors->keys() as $name) {
            $references[] = "#/components/errors/{$name}";
        }
    }

    if ($this->examples !== null) {
        foreach ($this->examples->keys() as $name) {
            $references[] = "#/components/examples/{$name}";
        }
    }

    if ($this->examplePairings !== null) {
        foreach ($this->examplePairings->keys() as $name) {
            $references[] = "#/components/examplePairings/{$name}";
        }
    }

    if ($this->links !== null) {
        foreach ($this->links->keys() as $name) {
            $references[] = "#/components/links/{$name}";
        }
    }

    if ($this->tags !== null) {
        foreach ($this->tags->keys() as $name) {
            $references[] = "#/components/tags/{$name}";
        }
    }

    if ($this->resources !== null) {
        foreach ($this->resources->keys() as $name) {
            $references[] = "#/components/resources/{$name}";
        }
    }

    return $references;
}
```

### 🟡 Minor Issue: Schemas Property Inconsistency
**Location:** Line 60

**Issue:** The `$schemas` property uses raw arrays (`array<string, array<string, mixed>>`) while other properties use typed Data objects. This inconsistency makes the API harder to use.

**Impact:** Developers must handle `$schemas` differently from other component types, leading to confusion and potential bugs.

**Solution:**
Create a JsonSchemaData class (as recommended in ArgumentData review):

```php
// Update line 60:
// Before:
public readonly ?array $schemas = null,

// After:
/** @var null|ComponentCollection<JsonSchemaData> */
public readonly ?ComponentCollection $schemas = null,
```

Update PHPDoc at lines 32-35:

```php
// Before:
* @param null|array<string, array<string, mixed>> $schemas

// After:
* @param null|ComponentCollection<JsonSchemaData> $schemas
```

### 🔵 Suggestion: Add Helper Methods for Component Registration
**Location:** Class-level

**Issue:** No convenient methods to add components after construction, requiring recreation of the entire object.

**Impact:** Reduced developer experience when building discovery documents programmatically.

**Solution:**
Add fluent builder methods:

```php
// Add these methods to ComponentsData.php:

/**
 * Add a schema component.
 *
 * @param string $name Schema identifier
 * @param array<string, mixed> $schema JSON Schema definition
 * @return self New instance with the schema added
 */
public function withSchema(string $name, array $schema): self
{
    $schemas = $this->schemas ?? [];
    $schemas[$name] = $schema;

    return new self(
        schemas: $schemas,
        contentDescriptors: $this->contentDescriptors,
        errors: $this->errors,
        examples: $this->examples,
        examplePairings: $this->examplePairings,
        links: $this->links,
        tags: $this->tags,
        resources: $this->resources,
    );
}

/**
 * Add a content descriptor component.
 *
 * @param string $name Descriptor identifier
 * @param ArgumentData $descriptor Content descriptor definition
 * @return self New instance with the descriptor added
 */
public function withContentDescriptor(string $name, ArgumentData $descriptor): self
{
    $descriptors = $this->contentDescriptors ?? [];
    $descriptors[$name] = $descriptor;

    return new self(
        schemas: $this->schemas,
        contentDescriptors: $descriptors,
        errors: $this->errors,
        examples: $this->examples,
        examplePairings: $this->examplePairings,
        links: $this->links,
        tags: $this->tags,
        resources: $this->resources,
    );
}

/**
 * Add an error component.
 *
 * @param string $name Error identifier
 * @param ErrorDefinitionData $error Error definition
 * @return self New instance with the error added
 */
public function withError(string $name, ErrorDefinitionData $error): self
{
    $errors = $this->errors ?? [];
    $errors[$name] = $error;

    return new self(
        schemas: $this->schemas,
        contentDescriptors: $this->contentDescriptors,
        errors: $errors,
        examples: $this->examples,
        examplePairings: $this->examplePairings,
        links: $this->links,
        tags: $this->tags,
        resources: $this->resources,
    );
}

/**
 * Add a resource component.
 *
 * @param string $name Resource type identifier
 * @param ResourceData $resource Resource definition
 * @return self New instance with the resource added
 */
public function withResource(string $name, ResourceData $resource): self
{
    $resources = $this->resources ?? [];
    $resources[$name] = $resource;

    return new self(
        schemas: $this->schemas,
        contentDescriptors: $this->contentDescriptors,
        errors: $this->errors,
        examples: $this->examples,
        examplePairings: $this->examplePairings,
        links: $this->links,
        tags: $this->tags,
        resources: $resources,
    );
}

/**
 * Merge another ComponentsData instance into this one.
 *
 * @param ComponentsData $other Component data to merge
 * @return self New instance with merged components
 */
public function merge(ComponentsData $other): self
{
    return new self(
        schemas: array_merge($this->schemas ?? [], $other->schemas ?? []),
        contentDescriptors: array_merge($this->contentDescriptors ?? [], $other->contentDescriptors ?? []),
        errors: array_merge($this->errors ?? [], $other->errors ?? []),
        examples: array_merge($this->examples ?? [], $other->examples ?? []),
        examplePairings: array_merge($this->examplePairings ?? [], $other->examplePairings ?? []),
        links: array_merge($this->links ?? [], $other->links ?? []),
        tags: array_merge($this->tags ?? [], $other->tags ?? []),
        resources: array_merge($this->resources ?? [], $other->resources ?? []),
    );
}
```

---

## Security Vulnerabilities

### 🟡 Minor Security Concern: Unvalidated Component Data
**Location:** All properties (lines 60-67)

**Issue:** Component data is accepted without validation. Malicious or malformed component definitions could be injected if the discovery document is built from untrusted sources.

**Impact:**
- Schema injection attacks through malformed JSON schemas
- Cross-site scripting (XSS) if components contain unescaped HTML
- Denial of service through excessively large or deeply nested schemas
- Circular references causing infinite loops during resolution

**Solution:**
Implement validation on construction:

```php
// Add this method to ComponentsData.php:

/**
 * Validate all components for security and structural issues.
 *
 * @throws \InvalidArgumentException if validation fails
 */
private function validate(): void
{
    // Validate schemas don't have excessive nesting
    if ($this->schemas !== null) {
        foreach ($this->schemas as $name => $schema) {
            $this->validateSchemaDepth($schema, $name);
        }
    }

    // Validate no circular references in components
    $this->validateNoCircularReferences();

    // Validate component names are safe identifiers
    $this->validateComponentNames();
}

/**
 * Validate schema depth to prevent DoS attacks.
 *
 * @param array<string, mixed> $schema Schema definition
 * @param string $name Schema name for error messages
 * @param int $depth Current nesting depth
 * @param int $maxDepth Maximum allowed depth (default 10)
 * @throws \InvalidArgumentException
 */
private function validateSchemaDepth(
    array $schema,
    string $name,
    int $depth = 0,
    int $maxDepth = 10,
): void {
    if ($depth > $maxDepth) {
        throw new \InvalidArgumentException(
            "Schema '{$name}' exceeds maximum nesting depth of {$maxDepth}"
        );
    }

    if (isset($schema['properties']) && is_array($schema['properties'])) {
        foreach ($schema['properties'] as $prop) {
            if (is_array($prop)) {
                $this->validateSchemaDepth($prop, $name, $depth + 1, $maxDepth);
            }
        }
    }

    if (isset($schema['items']) && is_array($schema['items'])) {
        $this->validateSchemaDepth($schema['items'], $name, $depth + 1, $maxDepth);
    }
}

/**
 * Validate component names are safe identifiers.
 *
 * @throws \InvalidArgumentException
 */
private function validateComponentNames(): void
{
    $pattern = '/^[a-zA-Z_][a-zA-Z0-9_-]*$/';

    $collections = [
        'schemas' => $this->schemas,
        'contentDescriptors' => $this->contentDescriptors,
        'errors' => $this->errors,
        'examples' => $this->examples,
        'examplePairings' => $this->examplePairings,
        'links' => $this->links,
        'tags' => $this->tags,
        'resources' => $this->resources,
    ];

    foreach ($collections as $type => $collection) {
        if ($collection === null) {
            continue;
        }

        $keys = is_array($collection) ? array_keys($collection) : $collection->keys()->toArray();

        foreach ($keys as $name) {
            if (!preg_match($pattern, $name)) {
                throw new \InvalidArgumentException(
                    "Invalid component name '{$name}' in {$type}. Names must match pattern: {$pattern}"
                );
            }
        }
    }
}

/**
 * Validate there are no circular references in component definitions.
 *
 * @throws \InvalidArgumentException
 */
private function validateNoCircularReferences(): void
{
    // Implementation would track $ref chains and detect cycles
    // This is complex and depends on how references are structured
    // Left as an exercise based on specific reference format
}
```

Then call `validate()` from the constructor:

```php
public function __construct(
    public readonly ?array $schemas = null,
    public readonly ?array $contentDescriptors = null,
    public readonly ?array $errors = null,
    public readonly ?array $examples = null,
    public readonly ?array $examplePairings = null,
    public readonly ?array $links = null,
    public readonly ?array $tags = null,
    public readonly ?array $resources = null,
) {
    $this->validate();
}
```

---

## Performance Concerns

### 🟡 Minor Performance Issue: Inefficient Component Lookups
**Location:** Class-level

**Issue:** Array-based storage requires linear search when checking for component existence or resolving references. For large component registries, this could become a bottleneck.

**Impact:** O(n) lookup time for component existence checks and resolutions. This could impact performance for discovery documents with hundreds of components.

**Solution:**
The current array-based approach is already using associative arrays with O(1) lookups by key, so this is actually not a real issue. The PHPDoc correctly indicates `array<string, T>` which provides efficient key-based access.

**Verification:**
```php
// Current implementation already has O(1) lookups:
$component = $this->schemas['UserSchema']; // O(1) hash table lookup
```

No action needed. Performance is already optimal for this use case.

---

## Maintainability Assessment

### Code Readability: Excellent ✅
- Clear property names indicating purpose
- Comprehensive PHPDoc for each parameter
- Well-organized structure grouping related components
- Logical ordering of parameters

### Documentation Quality: Excellent ✅
- Detailed explanations of each component type
- Clear description of how components promote reusability
- Reference to external documentation
- Author attribution and copyright notice

### Testing Considerations

**Recommended Test Cases:**

```php
// tests/Unit/Discovery/ComponentsDataTest.php
<?php declare(strict_types=1);

namespace Tests\Unit\Discovery;

use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\ComponentsData;
use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Discovery\ExampleData;
use Cline\Forrst\Discovery\Resource\ResourceData;
use Cline\Forrst\Discovery\TagData;
use PHPUnit\Framework\TestCase;

final class ComponentsDataTest extends TestCase
{
    /** @test */
    public function it_creates_empty_components_registry(): void
    {
        $components = new ComponentsData();

        $this->assertNull($components->schemas);
        $this->assertNull($components->contentDescriptors);
        $this->assertNull($components->errors);
        $this->assertNull($components->examples);
        $this->assertNull($components->examplePairings);
        $this->assertNull($components->links);
        $this->assertNull($components->tags);
        $this->assertNull($components->resources);
    }

    /** @test */
    public function it_stores_schemas_indexed_by_name(): void
    {
        $components = new ComponentsData(
            schemas: [
                'User' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                    ],
                ],
                'Post' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                ],
            ],
        );

        $this->assertArrayHasKey('User', $components->schemas);
        $this->assertArrayHasKey('Post', $components->schemas);
        $this->assertSame('object', $components->schemas['User']['type']);
    }

    /** @test */
    public function it_stores_content_descriptors_indexed_by_name(): void
    {
        $paginationDescriptor = new ArgumentData(
            name: 'page',
            schema: ['type' => 'integer', 'minimum' => 1],
            required: false,
            default: 1,
        );

        $components = new ComponentsData(
            contentDescriptors: [
                'Pagination' => $paginationDescriptor,
            ],
        );

        $this->assertArrayHasKey('Pagination', $components->contentDescriptors);
        $this->assertInstanceOf(ArgumentData::class, $components->contentDescriptors['Pagination']);
    }

    /** @test */
    public function it_stores_error_definitions_indexed_by_code(): void
    {
        $notFoundError = new ErrorDefinitionData(
            code: 'NOT_FOUND',
            message: 'Resource not found',
            httpStatusCode: 404,
        );

        $components = new ComponentsData(
            errors: [
                'NotFound' => $notFoundError,
            ],
        );

        $this->assertArrayHasKey('NotFound', $components->errors);
        $this->assertInstanceOf(ErrorDefinitionData::class, $components->errors['NotFound']);
    }

    /** @test */
    public function it_stores_multiple_component_types_simultaneously(): void
    {
        $components = new ComponentsData(
            schemas: [
                'User' => ['type' => 'object'],
            ],
            contentDescriptors: [
                'Auth' => new ArgumentData('token', ['type' => 'string']),
            ],
            errors: [
                'Unauthorized' => new ErrorDefinitionData('UNAUTHORIZED', 'Not authorized', 401),
            ],
            tags: [
                'Users' => new TagData('Users', 'User management operations'),
            ],
        );

        $this->assertNotNull($components->schemas);
        $this->assertNotNull($components->contentDescriptors);
        $this->assertNotNull($components->errors);
        $this->assertNotNull($components->tags);
        $this->assertNull($components->examples);
        $this->assertNull($components->examplePairings);
    }

    /** @test */
    public function it_validates_component_reference_format(): void
    {
        $components = new ComponentsData(
            schemas: [
                'User' => ['type' => 'object'],
            ],
        );

        $this->assertTrue($components->hasReference('#/components/schemas/User'));
        $this->assertFalse($components->hasReference('#/components/schemas/NonExistent'));
        $this->assertFalse($components->hasReference('invalid-reference'));
    }

    /** @test */
    public function it_resolves_component_references(): void
    {
        $userSchema = ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]];

        $components = new ComponentsData(
            schemas: [
                'User' => $userSchema,
            ],
        );

        $resolved = $components->resolveReference('#/components/schemas/User');

        $this->assertSame($userSchema, $resolved);
    }

    /** @test */
    public function it_throws_exception_for_invalid_reference_resolution(): void
    {
        $components = new ComponentsData();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Component reference '#/components/schemas/NonExistent' does not exist");

        $components->resolveReference('#/components/schemas/NonExistent');
    }

    /** @test */
    public function it_lists_all_available_references(): void
    {
        $components = new ComponentsData(
            schemas: [
                'User' => ['type' => 'object'],
                'Post' => ['type' => 'object'],
            ],
            errors: [
                'NotFound' => new ErrorDefinitionData('NOT_FOUND', 'Not found', 404),
            ],
        );

        $references = $components->getAllReferences();

        $this->assertContains('#/components/schemas/User', $references);
        $this->assertContains('#/components/schemas/Post', $references);
        $this->assertContains('#/components/errors/NotFound', $references);
        $this->assertCount(3, $references);
    }

    /** @test */
    public function readonly_properties_are_immutable(): void
    {
        $components = new ComponentsData(
            schemas: ['User' => ['type' => 'object']],
        );

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        $components->schemas = []; // @phpstan-ignore-line
    }

    /** @test */
    public function it_merges_components_from_another_instance(): void
    {
        $components1 = new ComponentsData(
            schemas: ['User' => ['type' => 'object']],
            errors: ['NotFound' => new ErrorDefinitionData('NOT_FOUND', 'Not found', 404)],
        );

        $components2 = new ComponentsData(
            schemas: ['Post' => ['type' => 'object']],
            tags: ['Users' => new TagData('Users', 'User operations')],
        );

        $merged = $components1->merge($components2);

        $this->assertArrayHasKey('User', $merged->schemas);
        $this->assertArrayHasKey('Post', $merged->schemas);
        $this->assertArrayHasKey('NotFound', $merged->errors);
        $this->assertArrayHasKey('Users', $merged->tags);
    }

    /** @test */
    public function it_validates_component_names_are_safe_identifiers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid component name");

        new ComponentsData(
            schemas: [
                'User Schema' => ['type' => 'object'], // Spaces not allowed
            ],
        );
    }

    /** @test */
    public function it_validates_schema_depth_limits(): void
    {
        $deeplyNestedSchema = [
            'type' => 'object',
            'properties' => [
                'level1' => [
                    'type' => 'object',
                    'properties' => [
                        'level2' => [
                            'type' => 'object',
                            'properties' => [
                                'level3' => [
                                    'type' => 'object',
                                    // Continue nesting beyond safe limits...
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum nesting depth');

        new ComponentsData(schemas: ['DeepSchema' => $deeplyNestedSchema]);
    }
}
```

---

## Summary of Recommendations

### Critical (Must Fix) 🔴
None identified.

### Major (Should Fix Soon) 🟠
1. **Enforce type constraints** (Lines 60-67) - Replace loose array types with strongly-typed ComponentCollection classes or validate array contents at runtime to ensure type safety.

### Minor (Consider Fixing) 🟡
1. **Add component reference validation** (Class-level) - Implement `hasReference()` and `resolveReference()` methods to validate and resolve `$ref` pointers
2. **Standardize schemas property** (Line 60) - Use ComponentCollection or JsonSchemaData for schemas to match other component types
3. **Add security validation** (Constructor) - Validate component names, schema depth, and prevent circular references to protect against injection attacks
4. **Add helper methods** (Class-level) - Implement `withSchema()`, `withContentDescriptor()`, etc. for fluent component registration

### Suggestions (Optional Improvements) 🔵
1. **Consider builder pattern** - Add static factory method for common component registry configurations
2. **Add comprehensive unit tests** - Cover all component types, reference validation, and edge cases
3. **Document reference format** - Add examples of valid `$ref` formats in PHPDoc
4. **Add component statistics** - Implement `getComponentCount()` method to report registry size

---

## Conclusion

**Overall Rating: 7.5/10**

ComponentsData.php serves as an effective component registry for API discovery documentation. The class provides a clear, organized structure for storing reusable components. However, the reliance on weakly-typed arrays instead of strongly-typed collections reduces type safety and makes the API more error-prone.

The main areas for improvement are:
1. **Type Safety**: Replace array types with ComponentCollection or similar to enforce component type constraints
2. **Reference Validation**: Add methods to validate and resolve component references
3. **Security**: Validate component data to prevent injection attacks and DoS through excessive nesting
4. **Developer Experience**: Add fluent builder methods for easier component registration

These improvements would significantly enhance the robustness, security, and usability of the component registry while maintaining its clean, simple interface.

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data;

/**
 * JSON:API compliant resource object structure.
 *
 * Encapsulates the core components of a JSON:API resource object including type
 * identification, unique identifier, attributes, relationships, and metadata.
 * This structure provides a standardized format for representing domain entities
 * in Forrst protocol responses, ensuring consistency and interoperability.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/resource-objects
 * @see https://jsonapi.org/format/#document-resource-objects
 */
final class ResourceObjectData extends AbstractData
{
    /**
     * Create a new resource object data instance.
     *
     * @param string                    $type          The resource type identifier, typically a singular, lowercase
     *                                                 snake_case representation of the resource model name (e.g., 'order',
     *                                                 'order_item'). Used for resource type identification and routing
     *                                                 in JSON:API compliant responses.
     * @param string                    $id            The unique identifier for this specific resource instance, typically
     *                                                 the primary key value cast as a string. Used for resource identification,
     *                                                 relationship references, and URL generation in JSON:API responses.
     * @param array<string, mixed>      $attributes    The resource's attribute data as a key-value array, containing
     *                                                 all non-relationship fields that describe the resource. Excludes the
     *                                                 id and type fields, which are represented separately in the JSON:API
     *                                                 specification.
     * @param null|array<string, mixed> $relationships Optional array of relationship data following JSON:API relationship
     *                                                 object structure. Each relationship includes linkage to related resources
     *                                                 via type and id references. Null when no relationships are included or
     *                                                 available for the resource.
     * @param null|array<string, mixed> $meta          Optional non-standard meta-information about the resource. Used for
     *                                                 permission indicators, cache hints, version numbers, or computed values
     *                                                 not represented in attributes.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly array $attributes,
        public readonly ?array $relationships = null,
        public readonly ?array $meta = null,
    ) {
        if ($type === '') {
            throw new \InvalidArgumentException('Resource type cannot be empty');
        }

        if ($id === '') {
            throw new \InvalidArgumentException('Resource id cannot be empty');
        }

        // JSON:API spec recommends lowercase, hyphenated or underscored type names
        if (!preg_match('/^[a-z][a-z0-9_-]*$/', $type)) {
            throw new \InvalidArgumentException(
                sprintf('Resource type "%s" must be lowercase and contain only letters, numbers, hyphens, or underscores', $type)
            );
        }
    }

    /**
     * Create a resource object data instance from an array.
     *
     * @param array<string, mixed> $data The array data containing resource object information
     * @return self Configured ResourceObjectData instance
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function createFromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? throw new \InvalidArgumentException('Resource type is required'),
            id: $data['id'] ?? throw new \InvalidArgumentException('Resource id is required'),
            attributes: isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [],
            relationships: isset($data['relationships']) && is_array($data['relationships']) ? $data['relationships'] : null,
            meta: isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : null,
        );
    }

    /**
     * Create a resource object data instance from explicit parameters.
     *
     * @param string $type The resource type identifier
     * @param string $id The resource unique identifier
     * @param array<string, mixed> $attributes The resource attributes
     * @param null|array<string, mixed> $relationships Optional relationships
     * @param null|array<string, mixed> $meta Optional meta-information
     * @return self Configured ResourceObjectData instance
     */
    public static function createFrom(
        string $type,
        string $id,
        array $attributes = [],
        ?array $relationships = null,
        ?array $meta = null,
    ): self {
        return new self(
            type: $type,
            id: $id,
            attributes: $attributes,
            relationships: $relationships,
            meta: $meta,
        );
    }

    /**
     * Check if the resource has any attributes.
     *
     * @return bool True if attributes are present
     */
    public function hasAttributes(): bool
    {
        return $this->attributes !== [];
    }

    /**
     * Check if the resource has relationships.
     *
     * @return bool True if relationships are present
     */
    public function hasRelationships(): bool
    {
        return $this->relationships !== null && $this->relationships !== [];
    }

    /**
     * Check if the resource has meta-information.
     *
     * @return bool True if meta is present
     */
    public function hasMeta(): bool
    {
        return $this->meta !== null && $this->meta !== [];
    }

    /**
     * Check if a specific attribute exists.
     *
     * @param string $key The attribute key
     * @return bool True if the attribute exists
     */
    public function hasAttribute(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Get a specific attribute value with optional default.
     *
     * @param string $key The attribute key
     * @param mixed $default The default value if not found
     * @return mixed The attribute value or default
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Check if a specific relationship exists.
     *
     * @param string $key The relationship key
     * @return bool True if the relationship exists
     */
    public function hasRelationship(string $key): bool
    {
        return isset($this->relationships[$key]);
    }

    /**
     * Get a specific relationship with optional default.
     *
     * @param string $key The relationship key
     * @param mixed $default The default value if not found
     * @return mixed The relationship or default
     */
    public function getRelationship(string $key, mixed $default = null): mixed
    {
        return $this->relationships[$key] ?? $default;
    }

    /**
     * Check if a specific meta field exists.
     *
     * @param string $key The meta key
     * @return bool True if the meta field exists
     */
    public function hasMeta(string $key): bool
    {
        return isset($this->meta[$key]);
    }

    /**
     * Get a specific meta value with optional default.
     *
     * @param string $key The meta key
     * @param mixed $default The default value if not found
     * @return mixed The meta value or default
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }
}

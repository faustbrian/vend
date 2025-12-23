<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Functions;

use Cline\Forrst\Contracts\ResourceInterface;
use Cline\Forrst\Data\DocumentData;
use Cline\Forrst\Discovery\ArgumentData;
use Override;

/**
 * Base class for Forrst list functions with standardized cursor pagination.
 *
 * Extends AbstractFunction to provide complete list endpoint functionality with cursor
 * pagination, sparse fieldsets, filtering, relationship inclusion, and sorting. Designed
 * for resource collection endpoints that need efficient pagination without offset limitations.
 *
 * The handle() method is pre-implemented to query the resource class, apply request
 * parameters, and return cursor-paginated results. Subclasses only need to specify
 * the resource class via getResourceClass().
 *
 * Automatically generates Forrst Discovery argument descriptors for standard list endpoint
 * parameters including cursor, limit, fields, filter, include, and sort. These descriptors
 * enable automatic API documentation and client code generation.
 *
 * SECURITY WARNING for Resource Implementations:
 * The resource class implementation MUST validate and whitelist all client-provided input:
 * - Validate filter attributes against a whitelist in getFilters()
 * - Validate relationship names against a whitelist in getRelationships()
 * - Validate sort attributes against database columns
 * - Never trust client-provided attribute names without validation
 * - Prevent query injection by sanitizing all filter values
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/forrst/extensions/query
 */
abstract class AbstractListFunction extends AbstractFunction
{
    /**
     * Handle the list request and return paginated results.
     *
     * Builds a query using the resource class returned by getResourceClass(), applies
     * request filters and parameters through the query() helper, and returns paginated
     * results wrapped in a DocumentData structure with pagination metadata.
     *
     * The pagination strategy is determined by getPaginationStrategy():
     * - 'cursor': Cursor-based pagination (default, best for real-time feeds)
     * - 'offset': Offset-based pagination (best for traditional page numbers)
     * - 'simple': Simple next/prev pagination (best for large datasets)
     * - 'none': Return all results without pagination (use cautiously)
     *
     * Provides lifecycle hooks for customization:
     * - beforePagination(): Apply custom query modifications before pagination
     * - afterPagination(): Post-process the paginated result before returning
     *
     * @return DocumentData The paginated resource collection with pagination metadata and links
     */
    public function handle(): DocumentData
    {
        $query = $this->query($this->getValidatedResourceClass());
        $query = $this->beforePagination($query);

        $result = match ($this->getPaginationStrategy()) {
            'cursor' => $this->cursorPaginate($query),
            'offset' => $this->paginate($query),
            'simple' => $this->simplePaginate($query),
            'none' => $this->collection($query->get()),
            default => throw new \InvalidArgumentException(
                \sprintf(
                    'Invalid pagination strategy "%s". Must be one of: cursor, offset, simple, none',
                    $this->getPaginationStrategy(),
                ),
            ),
        };

        return $this->afterPagination($result);
    }

    /**
     * Apply custom query modifications before pagination.
     *
     * Override this method to add custom scopes, eager loading, or filters
     * that should always apply to this list function regardless of request parameters.
     *
     * Example use cases:
     * - Always filter to published/active records
     * - Apply tenant-specific filtering
     * - Add time-based filters (e.g., published_at <= now())
     * - Optimize with additional eager loading
     *
     * @param \Illuminate\Database\Eloquent\Builder $query The query builder to modify
     *
     * @return \Illuminate\Database\Eloquent\Builder The modified query builder
     */
    protected function beforePagination(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query;
    }

    /**
     * Post-process the paginated result before returning.
     *
     * Override this to add custom metadata, inject additional data,
     * or transform the result structure.
     *
     * Example use cases:
     * - Add aggregated statistics to metadata
     * - Inject computed totals or counts
     * - Add custom pagination metadata
     * - Transform result structure for specific client needs
     *
     * @param DocumentData $result The paginated result
     *
     * @return DocumentData The modified result
     */
    protected function afterPagination(DocumentData $result): DocumentData
    {
        return $result;
    }

    /**
     * Get and validate the resource class.
     *
     * Validates that the resource class returned by getResourceClass() exists and
     * implements ResourceInterface. This provides early error detection with clear
     * messages when subclasses misconfigure their resource class.
     *
     * @throws \InvalidArgumentException When resource class is invalid or doesn't implement ResourceInterface
     *
     * @return class-string<ResourceInterface> The validated resource class name
     */
    final protected function getValidatedResourceClass(): string
    {
        $resourceClass = $this->getResourceClass();

        // Validate it's actually a class
        if (!\class_exists($resourceClass)) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Resource class "%s" does not exist in %s',
                    $resourceClass,
                    static::class,
                ),
            );
        }

        // Validate it implements ResourceInterface
        if (!\is_subclass_of($resourceClass, ResourceInterface::class)) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Resource class "%s" must implement %s in %s',
                    $resourceClass,
                    ResourceInterface::class,
                    static::class,
                ),
            );
        }

        return $resourceClass;
    }

    /**
     * Get the pagination strategy to use for this list function.
     *
     * Available strategies:
     * - 'cursor': Cursor-based pagination (default, best for real-time feeds)
     * - 'offset': Offset-based pagination (best for traditional page numbers)
     * - 'simple': Simple next/prev pagination (best for large datasets)
     * - 'none': Return all results without pagination (use cautiously)
     *
     * Override this method to change the pagination strategy for specific list functions.
     *
     * @return string The pagination strategy identifier
     */
    protected function getPaginationStrategy(): string
    {
        return 'cursor';
    }

    /**
     * Get the default pagination limit when not specified in request.
     *
     * @return int Default limit (must be between 1 and maximum allowed)
     */
    protected function getDefaultLimit(): int
    {
        return 25;
    }

    /**
     * Get the maximum allowed pagination limit.
     *
     * @return int Maximum limit
     */
    protected function getMaximumLimit(): int
    {
        return 100;
    }

    /**
     * Get Forrst Discovery argument descriptors for the list function.
     *
     * Generates standard list endpoint argument definitions including pagination
     * (cursor/offset/simple/none based on strategy), sparse fieldsets (fields),
     * filtering (filter), relationship inclusion (include), and sorting (sort).
     * These descriptors enable automatic API documentation and client code generation.
     *
     * @return array<int, ArgumentData|array<string, mixed>> Standard list endpoint argument descriptors
     */
    #[Override()]
    public function getArguments(): array
    {
        // Validate resource class early to fail fast with clear error
        $this->getValidatedResourceClass();

        $strategy = $this->getPaginationStrategy();
        $arguments = [];

        // Add pagination arguments based on strategy
        if ($strategy === 'cursor') {
            $arguments[] = ArgumentData::from([
                'name' => 'cursor',
                'schema' => ['type' => 'string'],
                'required' => false,
                'description' => 'Pagination cursor for the next page',
            ]);
        } elseif ($strategy === 'offset') {
            $arguments[] = ArgumentData::from([
                'name' => 'page',
                'schema' => ['type' => 'integer', 'minimum' => 1],
                'required' => false,
                'default' => 1,
                'description' => 'Page number',
            ]);
        }

        // Limit is common to all strategies except 'none'
        if ($strategy !== 'none') {
            $arguments[] = ArgumentData::from([
                'name' => 'limit',
                'schema' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => $this->getMaximumLimit(),
                ],
                'required' => false,
                'default' => $this->getDefaultLimit(),
                'description' => 'Number of items per page',
            ]);
        }

        // Common query arguments
        $arguments = [...$arguments, ...$this->getQueryArguments(), ...$this->getCustomArguments()];

        return $arguments;
    }

    /**
     * Get additional custom arguments specific to this list function.
     *
     * Override this method to add resource-specific query parameters
     * beyond the standard fields, filter, include, and sort arguments.
     *
     * Example use cases:
     * - Add boolean flags for common filters (e.g., in_stock, published)
     * - Add enum arguments for specific categorizations
     * - Add date range filters
     * - Add resource-specific search parameters
     *
     * @return list<ArgumentData> Additional argument descriptors
     */
    protected function getCustomArguments(): array
    {
        return [];
    }

    /**
     * Get standard query arguments (fields, filter, include, sort).
     *
     * These arguments are common to all list functions regardless of pagination strategy.
     *
     * @return list<ArgumentData> Standard query argument descriptors
     */
    protected function getQueryArguments(): array
    {
        return [
            // Sparse fieldsets
            ArgumentData::from([
                'name' => 'fields',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => false,
                'description' => 'Sparse fieldset selection by resource type',
                'examples' => [['self' => ['id', 'name', 'created_at']]],
            ]),
            // Filters
            ArgumentData::from([
                'name' => 'filter',
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'required' => false,
                'description' => 'Filter criteria',
            ]),
            // Relationships to include
            ArgumentData::from([
                'name' => 'include',
                'schema' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'required' => false,
                'description' => 'Relationships to include',
                'examples' => [['customer', 'items']],
            ]),
            // Sorting
            ArgumentData::from([
                'name' => 'sort',
                'schema' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'attribute' => ['type' => 'string'],
                            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                        ],
                        'required' => ['attribute'],
                    ],
                ],
                'required' => false,
                'description' => 'Sort order specification',
                'examples' => [[['attribute' => 'created_at', 'direction' => 'desc']]],
            ]),
        ];
    }

    /**
     * Get the resource class defining fields, filters, and relationships.
     *
     * Implement this method to specify the ResourceInterface implementation that
     * defines available fields, filterable attributes, loadable relationships, and
     * transformation logic for this list endpoint.
     *
     * @return class-string<ResourceInterface> The fully-qualified resource class name
     */
    abstract protected function getResourceClass(): string;
}

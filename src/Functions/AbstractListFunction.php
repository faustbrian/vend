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
     * @return DocumentData The paginated resource collection with pagination metadata and links
     */
    public function handle(): DocumentData
    {
        $query = $this->query($this->getResourceClass());

        return match ($this->getPaginationStrategy()) {
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
     * Generates standard list endpoint argument definitions including cursor pagination
     * (cursor, limit), sparse fieldsets (fields), filtering (filter), relationship
     * inclusion (include), and sorting (sort). These descriptors enable automatic
     * API documentation and client code generation.
     *
     * @return array<int, ArgumentData|array<string, mixed>> Standard list endpoint argument descriptors
     */
    #[Override()]
    public function getArguments(): array
    {
        $this->getResourceClass();

        return [
            // Cursor pagination arguments
            ArgumentData::from([
                'name' => 'cursor',
                'schema' => ['type' => 'string'],
                'required' => false,
                'description' => 'Pagination cursor for the next page',
            ]),
            ArgumentData::from([
                'name' => 'limit',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'required' => false,
                'default' => 25,
                'description' => 'Number of items per page',
            ]),
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

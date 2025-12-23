<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Functions\Concerns;

use Cline\Forrst\Contracts\ResourceInterface;
use Cline\Forrst\Data\DocumentData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\QueryBuilders\QueryBuilder;
use Cline\Forrst\Transformers\Transformer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Data transformation helper trait for Forrst functions.
 *
 * Provides convenient methods for transforming Eloquent models, collections, and
 * paginated results into JSON API-compliant DocumentData structures. Automatically
 * applies field selection, relationship loading, and metadata inclusion based on
 * request parameters.
 *
 * All transformation methods create a Transformer instance with the current request
 * object, enabling automatic extraction of fields, include, and other transformation
 * parameters. The resulting DocumentData structures conform to Forrst protocol requirements.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @property RequestObjectData $requestObject The current Forrst request object
 *
 * @see https://docs.cline.sh/forrst/protocol
 */
trait InteractsWithTransformer
{
    /**
     * Get transformer configuration options.
     *
     * Override this method to customize transformation behavior for all methods
     * in this trait. Configuration options are passed to the Transformer instance
     * during creation, enabling fine-grained control over transformation output.
     *
     * Available options:
     * - include_meta: Include metadata in responses (default: true)
     * - include_links: Include navigation links (default: true)
     * - sparse_fieldsets: Enable field selection via request parameters (default: true)
     * - relationship_loading: Control eager loading strategy (default: 'auto')
     *
     * Example usage:
     * ```php
     * protected function getTransformerOptions(): array
     * {
     *     return [
     *         'include_meta' => false,
     *         'sparse_fieldsets' => true,
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed> Configuration options for the Transformer
     */
    protected function getTransformerOptions(): array
    {
        return [];
    }

    /**
     * Transform a single model or resource into a Forrst document.
     *
     * Converts an Eloquent model or ResourceInterface instance into a JSON API-compliant
     * DocumentData structure. Applies field selection and relationship loading based on
     * request parameters (fields, include).
     *
     * @param  ResourceInterface|Model $item The model or resource to transform
     * @return DocumentData            JSON API document containing the transformed item with metadata
     */
    protected function item(ResourceInterface|Model $item): DocumentData
    {
        return Transformer::create($this->requestObject, $this->getTransformerOptions())->item($item);
    }

    /**
     * Transform a collection of models into a Forrst document.
     *
     * Converts a collection of Eloquent models into a JSON API-compliant DocumentData
     * structure. Applies field selection and relationship loading to all items based
     * on request parameters.
     *
     * @param  Collection<int, Model> $collection The collection of models to transform
     * @return DocumentData           JSON API document containing the transformed collection with metadata
     */
    protected function collection(Collection $collection): DocumentData
    {
        return Transformer::create($this->requestObject, $this->getTransformerOptions())->collection($collection);
    }

    /**
     * Execute cursor pagination and transform results into a Forrst document.
     *
     * Applies cursor-based pagination to the query and transforms results into a
     * DocumentData structure with pagination metadata and navigation links. Cursor
     * pagination enables efficient navigation through large datasets without offset
     * performance degradation.
     *
     * @param  QueryBuilder|Builder<Model> $query The query to paginate and transform
     * @return DocumentData                JSON API document with paginated results and cursor metadata
     */
    protected function cursorPaginate(QueryBuilder|Builder $query): DocumentData
    {
        return Transformer::create($this->requestObject, $this->getTransformerOptions())->cursorPaginate($query);
    }

    /**
     * Execute offset pagination and transform results into a Forrst document.
     *
     * Applies traditional offset-based pagination to the query and transforms results
     * into a DocumentData structure with pagination metadata including current page,
     * total pages, per-page count, and navigation links.
     *
     * @param  QueryBuilder|Builder<Model> $query The query to paginate and transform
     * @return DocumentData                JSON API document with paginated results and page metadata
     */
    protected function paginate(QueryBuilder|Builder $query): DocumentData
    {
        return Transformer::create($this->requestObject, $this->getTransformerOptions())->paginate($query);
    }

    /**
     * Execute simple pagination and transform results into a Forrst document.
     *
     * Applies simple pagination without total count calculation for improved performance.
     * Provides only next/previous links without total page information. Ideal for large
     * datasets where count queries are expensive or unnecessary.
     *
     * @param  QueryBuilder|Builder<Model> $query The query to paginate and transform
     * @return DocumentData                JSON API document with paginated results and basic navigation links
     */
    protected function simplePaginate(QueryBuilder|Builder $query): DocumentData
    {
        return Transformer::create($this->requestObject, $this->getTransformerOptions())->simplePaginate($query);
    }
}

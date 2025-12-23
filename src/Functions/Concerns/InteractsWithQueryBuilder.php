<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Functions\Concerns;

use Cline\Forrst\Contracts\ResourceInterface;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\QueryBuilders\QueryBuilder;

/**
 * Query builder helper trait for Forrst functions.
 *
 * Provides convenient methods for initializing resource query builders with automatic
 * parameter resolution from Forrst request objects. Simplifies building queries with
 * filters, sorts, field selection, and relationship loading based on request parameters.
 *
 * The query() method delegates to the resource class's static query() method, passing
 * the current request object for automatic extraction of filter, sort, fields, and
 * include parameters. This enables standardized query building across all functions.
 *
 * **Requirements:**
 * - Host class must have RequestObjectData $requestObject property
 * - Host class must call setRequest() before using query()
 * - Resource classes must implement ResourceInterface
 * - Resource classes must have static query(RequestObjectData): QueryBuilder method
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @property RequestObjectData $requestObject The current Forrst request object (required)
 *
 * @see https://docs.cline.sh/forrst/extensions/query
 */
trait InteractsWithQueryBuilder
{
    /**
     * Create a query builder for a resource class with request parameters applied.
     *
     * Initializes a QueryBuilder instance by calling the resource class's static query()
     * method with the current request object. The request object is automatically parsed
     * to extract and apply filters, sorts, field selections, and relationship inclusions.
     *
     * @param  class-string<ResourceInterface> $class The resource class to query
     * @return QueryBuilder                    QueryBuilder instance with request parameters applied
     *
     * @throws \InvalidArgumentException If the class does not exist or does not implement ResourceInterface
     * @throws \BadMethodCallException   If the class does not have a static query() method
     */
    protected function query(string $class): QueryBuilder
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException(
                sprintf('Resource class "%s" does not exist', $class),
            );
        }

        if (!method_exists($class, 'query')) {
            throw new \BadMethodCallException(
                sprintf(
                    'Resource class "%s" must implement static query() method',
                    $class,
                ),
            );
        }

        if (!is_subclass_of($class, ResourceInterface::class)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Class "%s" must implement %s',
                    $class,
                    ResourceInterface::class,
                ),
            );
        }

        // @phpstan-ignore-next-line staticMethod.notFound, return.type - query() is defined on AbstractResource
        return $class::query($this->requestObject);
    }
}

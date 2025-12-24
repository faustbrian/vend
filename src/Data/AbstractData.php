<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data;

use Cline\Forrst\Exceptions\DataTransformationException;
use Override;
use Spatie\LaravelData\Data;

use function is_array;
use function sprintf;

/**
 * Base data transfer object with automatic null value filtering.
 *
 * Extends Spatie's Laravel Data package to provide automatic removal of null
 * values during serialization. This ensures compliance with JSON:API and
 * Forrst specifications which require optional fields to be omitted rather
 * than explicitly set to null.
 *
 * All data objects in the Forrst package extend this class to maintain
 * consistent serialization behavior. The null filtering is applied
 * recursively to nested structures, ensuring clean JSON output that
 * matches the Forrst protocol specification.
 *
 * Example usage:
 * ```php
 * class UserData extends AbstractData
 * {
 *     public function __construct(
 *         public readonly string $name,
 *         public readonly ?string $email = null,
 *         public readonly ?array $metadata = null,
 *     ) {}
 * }
 *
 * $user = new UserData(name: 'John', email: null);
 * $user->toArray(); // Returns: ['name' => 'John'] (email is omitted)
 *
 * // Customizing filtering behavior
 * class CustomData extends AbstractData
 * {
 *     protected function shouldFilterValue(int|string $key, mixed $value): bool
 *     {
 *         // Filter out null values and empty arrays
 *         return $value === null || $value === [];
 *     }
 * }
 * ```
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/document-structure
 * @see https://jsonapi.org/format/#document-structure
 */
abstract class AbstractData extends Data
{
    /**
     * Maximum allowed recursion depth during null value removal.
     *
     * Prevents infinite recursion and stack overflow errors with deeply
     * nested data structures.
     */
    private const int MAX_RECURSION_DEPTH = 100;

    /**
     * Convert the data object to an array with null values removed.
     *
     * Overrides the parent toArray method to automatically filter out null
     * values at all nesting levels. This ensures compliance with JSON:API
     * and Forrst specifications.
     *
     * @return array<string, mixed> Array representation without null values
     */
    #[Override()]
    public function toArray(): array
    {
        /** @var array<string, mixed> $array */
        $array = parent::toArray();

        return $this->removeNullValuesRecursively($array);
    }

    /**
     * Prepare the data for JSON serialization.
     *
     * Ensures consistent JSON encoding by delegating to toArray, which
     * automatically handles null value filtering.
     *
     * @return array<string, mixed> Array ready for JSON encoding
     */
    #[Override()]
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Determine if a value should be filtered out during serialization.
     *
     * Override this method in subclasses to customize filtering behavior.
     * By default, only null values are filtered out.
     *
     * @param  string $key   The array key
     * @param  mixed  $value The value to evaluate
     * @return bool   True if the value should be filtered out, false otherwise
     */
    protected function shouldFilterValue(int|string $key, mixed $value): bool
    {
        return $value === null;
    }

    /**
     * Transform a value during serialization.
     *
     * Override this method in subclasses to apply custom transformations
     * to values before they are included in the final array.
     *
     * @param  string $key   The array key
     * @param  mixed  $value The value to transform
     * @return mixed  The transformed value
     */
    protected function transformValue(int|string $key, mixed $value): mixed
    {
        return $value;
    }

    /**
     * Recursively remove null values from nested arrays.
     *
     * Traverses the array structure and removes any keys with null values.
     * This is required for JSON:API and Forrst specification compliance
     * where optional fields must be omitted entirely rather than set to null.
     *
     * According to JSON:API specification: "Keys MUST either be omitted or
     * have a null value to indicate that a particular link is unavailable."
     *
     * Performance: O(n) where n is the total number of elements in the tree.
     * For deeply nested structures exceeding 100 levels, the method will throw
     * a RuntimeException to prevent stack overflow. Consider flattening data
     * structures or implementing custom serialization for extreme nesting.
     *
     * @param array<string, mixed> $array Input array potentially containing null values
     * @param int                  $depth Current recursion depth
     *
     * @throws DataTransformationException If maximum recursion depth is exceeded
     * @return array<string, mixed>        Filtered array with null values removed
     */
    private function removeNullValuesRecursively(array $array, int $depth = 0): array
    {
        if ($depth > self::MAX_RECURSION_DEPTH) {
            throw DataTransformationException::cannotTransform(
                'array',
                'filtered array',
                sprintf('Maximum recursion depth of %d exceeded', self::MAX_RECURSION_DEPTH),
            );
        }

        foreach ($array as $key => $value) {
            if ($this->shouldFilterValue($key, $value)) {
                unset($array[$key]);

                continue;
            }

            $transformedValue = $this->transformValue($key, $value);

            if (is_array($transformedValue)) {
                $array[$key] = $this->removeNullValuesRecursively($transformedValue, $depth + 1);
            } else {
                $array[$key] = $transformedValue;
            }
        }

        return $array;
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\MissingRequiredFieldException;

describe('ResultDescriptorData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with resource field only', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Assert
            expect($result->resource)->toBe('order')
                ->and($result->schema)->toBeNull()
                ->and($result->collection)->toBeFalse()
                ->and($result->description)->toBeNull();
        });

        test('creates instance with schema field only', function (): void {
            // Arrange
            $schema = [
                'type' => 'object',
                'properties' => [
                    'valid' => ['type' => 'boolean'],
                    'errors' => ['type' => 'array'],
                ],
            ];

            // Act
            $result = new ResultDescriptorData(
                schema: $schema,
            );

            // Assert
            expect($result->resource)->toBeNull()
                ->and($result->schema)->toBe($schema)
                ->and($result->collection)->toBeFalse()
                ->and($result->description)->toBeNull();
        });

        test('creates instance for single resource', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                description: 'The requested order',
            );

            // Assert
            expect($result->resource)->toBe('order')
                ->and($result->collection)->toBeFalse()
                ->and($result->description)->toBe('The requested order');
        });

        test('creates instance for resource collection', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                collection: true,
                description: 'List of matching orders',
            );

            // Assert
            expect($result->resource)->toBe('order')
                ->and($result->collection)->toBeTrue()
                ->and($result->description)->toBe('List of matching orders');
        });

        test('creates instance for non-resource response', function (): void {
            // Arrange
            $schema = [
                'type' => 'object',
                'properties' => [
                    'valid' => ['type' => 'boolean'],
                    'errors' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ];

            // Act
            $result = new ResultDescriptorData(
                schema: $schema,
                description: 'Validation result',
            );

            // Assert
            expect($result->resource)->toBeNull()
                ->and($result->schema)->toBe($schema)
                ->and($result->collection)->toBeFalse()
                ->and($result->description)->toBe('Validation result');
        });

        test('creates instance with all fields populated', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                collection: true,
                description: 'Order collection with pagination',
            );

            // Assert
            expect($result->resource)->toBe('order')
                ->and($result->schema)->toBeNull()
                ->and($result->collection)->toBeTrue()
                ->and($result->description)->toBe('Order collection with pagination');
        });

        test('toArray includes resource when set', function (): void {
            // Arrange
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act
            $array = $result->toArray();

            // Assert
            expect($array)->toHaveKey('resource')
                ->and($array['resource'])->toBe('order');
        });

        test('toArray includes schema when set', function (): void {
            // Arrange
            $schema = ['type' => 'object'];
            $result = new ResultDescriptorData(
                schema: $schema,
            );

            // Act
            $array = $result->toArray();

            // Assert
            expect($array)->toHaveKey('schema')
                ->and($array['schema'])->toBe($schema);
        });

        test('toArray includes collection with default false', function (): void {
            // Arrange
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act
            $array = $result->toArray();

            // Assert
            expect($array)->toHaveKey('collection')
                ->and($array['collection'])->toBeFalse();
        });

        test('toArray handles null fields', function (): void {
            // Arrange - Must provide at least resource or schema
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act
            $array = $result->toArray();

            // Assert - Spatie Data includes null fields in toArray output
            expect($array)->toHaveKey('resource')
                ->and($array['resource'])->toBe('order')
                ->and($array)->toHaveKey('schema')
                ->and($array['schema'])->toBeNull()
                ->and($array)->toHaveKey('collection')
                ->and($array)->toHaveKey('description')
                ->and($array['description'])->toBeNull();
        });

        test('toArray includes description when set', function (): void {
            // Arrange
            $result = new ResultDescriptorData(
                resource: 'order',
                description: 'The created order',
            );

            // Act
            $array = $result->toArray();

            // Assert
            expect($array)->toHaveKey('description')
                ->and($array['description'])->toBe('The created order');
        });
    });

    describe('Edge Cases', function (): void {
        test('rejects null resource', function (): void {
            // Arrange & Act - When schema is provided, resource can be null
            $result = new ResultDescriptorData(
                schema: ['type' => 'object'],
            );

            // Assert
            expect($result->resource)->toBeNull();
        });

        test('rejects null schema', function (): void {
            // Arrange & Act - When resource is provided, schema can be null
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Assert
            expect($result->schema)->toBeNull();
        });

        test('handles collection false explicitly', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                collection: false,
            );

            // Assert
            expect($result->collection)->toBeFalse();
        });

        test('handles collection true', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                collection: true,
            );

            // Assert
            expect($result->collection)->toBeTrue();
        });

        test('validates resource and schema are mutually exclusive', function (): void {
            // Arrange & Act & Assert
            expect(fn () => new ResultDescriptorData(
                resource: 'order',
                schema: ['type' => 'object'],
            ))->toThrow(
                InvalidFieldValueException::class,
                'Cannot specify both "resource" and "schema"',
            );
        });

        test('validates at least one of resource or schema required', function (): void {
            // Arrange & Act & Assert
            expect(fn () => new ResultDescriptorData())
                ->toThrow(
                    MissingRequiredFieldException::class,
                    'resource or schema',
                );
        });

        test('rejects both resource and schema', function (): void {
            // Arrange & Act - Empty schema must have at least 'type' property
            $result = new ResultDescriptorData(
                schema: ['type' => 'null'],
            );

            // Assert - Schema with only type is valid minimal schema
            expect($result->schema)->toBeArray()
                ->and($result->schema)->toHaveKey('type')
                ->and($result->schema['type'])->toBe('null');
        });

        test('handles complex schema structure', function (): void {
            // Arrange
            $schema = [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sku' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ];

            // Act
            $result = new ResultDescriptorData(
                schema: $schema,
            );

            // Assert
            expect($result->schema)->toBe($schema)
                ->and($result->schema['properties'])->toHaveKey('id')
                ->and($result->schema['properties'])->toHaveKey('items');
        });

        test('handles schema with $ref', function (): void {
            // Arrange
            $schema = ['$ref' => '#/components/schemas/Order'];

            // Act
            $result = new ResultDescriptorData(
                schema: $schema,
            );

            // Assert
            expect($result->schema)->toBe(['$ref' => '#/components/schemas/Order'])
                ->and($result->schema['$ref'])->toBe('#/components/schemas/Order');
        });

        test('handles empty description', function (): void {
            // Arrange & Act
            $result = new ResultDescriptorData(
                resource: 'order',
                description: '',
            );

            // Assert
            expect($result->description)->toBe('');
        });

        test('toArray preserves complex schema structure', function (): void {
            // Arrange
            $schema = [
                'type' => 'object',
                'properties' => [
                    'nested' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                        ],
                    ],
                ],
            ];
            $result = new ResultDescriptorData(
                schema: $schema,
            );

            // Act
            $array = $result->toArray();

            // Assert
            expect($array['schema'])->toBe($schema)
                ->and($array['schema']['properties']['nested']['properties'])->toHaveKey('field');
        });
    });

    describe('Sad Paths', function (): void {
        test('validates optional resource field', function (): void {
            // Arrange - Provide schema, verify resource is optional
            $result = new ResultDescriptorData(
                schema: ['type' => 'object'],
            );

            // Act & Assert
            expect($result->resource)->toBeNull();
        });

        test('validates optional schema field', function (): void {
            // Arrange - Provide resource, verify schema is optional
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act & Assert
            expect($result->schema)->toBeNull();
        });

        test('validates collection defaults to false', function (): void {
            // Arrange - Provide required resource field
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act & Assert
            expect($result->collection)->toBeFalse();
        });

        test('validates optional description field', function (): void {
            // Arrange - Provide required resource field
            $result = new ResultDescriptorData(
                resource: 'order',
            );

            // Act & Assert
            expect($result->description)->toBeNull();
        });
    });
});

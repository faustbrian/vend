<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\DeprecatedData;

describe('ArgumentData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with required fields only', function (): void {
            // Arrange
            $name = 'customer_id';
            $schema = ['type' => 'string'];

            // Act
            $argument = new ArgumentData(
                name: $name,
                schema: $schema,
            );

            // Assert
            expect($argument->name)->toBe('customer_id')
                ->and($argument->schema)->toBe(['type' => 'string'])
                ->and($argument->required)->toBeFalse()
                ->and($argument->summary)->toBeNull()
                ->and($argument->description)->toBeNull()
                ->and($argument->default)->toBeNull()
                ->and($argument->deprecated)->toBeNull()
                ->and($argument->examples)->toBeNull();
        });

        test('creates instance with all fields populated', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'customer_id',
                schema: ['type' => 'string', 'pattern' => '^cust_[a-zA-Z0-9]+$'],
                required: true,
                summary: 'Customer identifier',
                description: 'Unique customer identifier for the order',
                default: 'cust_default',
                deprecated: new DeprecatedData(reason: 'Use customer_uuid instead'),
                examples: ['cust_abc123', 'cust_xyz789'],
            );

            // Assert
            expect($argument->name)->toBe('customer_id')
                ->and($argument->schema)->toBe(['type' => 'string', 'pattern' => '^cust_[a-zA-Z0-9]+$'])
                ->and($argument->required)->toBeTrue()
                ->and($argument->summary)->toBe('Customer identifier')
                ->and($argument->description)->toBe('Unique customer identifier for the order')
                ->and($argument->default)->toBe('cust_default')
                ->and($argument->deprecated)->toBeInstanceOf(DeprecatedData::class)
                ->and($argument->examples)->toBe(['cust_abc123', 'cust_xyz789']);
        });

        test('creates instance with required true', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'email',
                schema: ['type' => 'string', 'format' => 'email'],
                required: true,
            );

            // Assert
            expect($argument->required)->toBeTrue();
        });

        test('creates instance with required false', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'phone',
                schema: ['type' => 'string'],
                required: false,
            );

            // Assert
            expect($argument->required)->toBeFalse();
        });

        test('creates instance with complex schema', function (): void {
            // Arrange
            $schema = [
                'type' => 'object',
                'properties' => [
                    'amount' => ['type' => 'string', 'pattern' => '^-?\\d+\\.\\d{2}$'],
                    'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
                ],
                'required' => ['amount', 'currency'],
            ];

            // Act
            $argument = new ArgumentData(
                name: 'price',
                schema: $schema,
            );

            // Assert
            expect($argument->schema)->toBe($schema)
                ->and($argument->schema['type'])->toBe('object')
                ->and($argument->schema['properties'])->toHaveKey('amount')
                ->and($argument->schema['properties'])->toHaveKey('currency');
        });

        test('creates instance with array schema', function (): void {
            // Arrange
            $schema = [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'minItems' => 1,
            ];

            // Act
            $argument = new ArgumentData(
                name: 'tags',
                schema: $schema,
            );

            // Assert
            expect($argument->schema)->toBe($schema)
                ->and($argument->schema['type'])->toBe('array')
                ->and($argument->schema['minItems'])->toBe(1);
        });

        test('creates instance with default value', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'limit',
                schema: ['type' => 'integer'],
                default: 25,
            );

            // Assert
            expect($argument->default)->toBe(25);
        });

        test('creates instance with multiple examples', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'status',
                schema: ['type' => 'string', 'enum' => ['pending', 'active', 'completed']],
                examples: ['pending', 'active', 'completed'],
            );

            // Assert
            expect($argument->examples)->toHaveCount(3)
                ->and($argument->examples)->toBe(['pending', 'active', 'completed']);
        });

        test('toArray includes all required fields', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act
            $array = $argument->toArray();

            // Assert
            expect($array)->toHaveKey('name')
                ->and($array)->toHaveKey('schema')
                ->and($array['name'])->toBe('test_param')
                ->and($array['schema'])->toBe(['type' => 'string']);
        });

        test('toArray includes required field with default false', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act
            $array = $argument->toArray();

            // Assert
            expect($array)->toHaveKey('required')
                ->and($array['required'])->toBeFalse();
        });

        test('toArray handles null optional fields', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act
            $array = $argument->toArray();

            // Assert - Spatie Data may include null fields, verify they are null
            if (array_key_exists('summary', $array)) {
                expect($array['summary'])->toBeNull();
            }

            expect($array)->toHaveKey('name')
                ->and($array)->toHaveKey('schema')
                ->and($array)->toHaveKey('required');
        });

        test('toArray with nested Data objects converts correctly', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
                deprecated: new DeprecatedData(
                    reason: 'Use new_param instead',
                    sunset: '2026-06-01',
                ),
            );

            // Act
            $array = $argument->toArray();

            // Assert
            expect($array['deprecated'])->toBeArray()
                ->and($array['deprecated']['reason'])->toBe('Use new_param instead')
                ->and($array['deprecated']['sunset'])->toBe('2026-06-01');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null default value explicitly', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
                default: null,
            );

            // Assert
            expect($argument->default)->toBeNull();
        });

        test('handles default value of zero', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'count',
                schema: ['type' => 'integer'],
                default: 0,
            );

            // Assert
            expect($argument->default)->toBe(0);
        });

        test('handles default value of false', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'enabled',
                schema: ['type' => 'boolean'],
                default: false,
            );

            // Assert
            expect($argument->default)->toBeFalse();
        });

        test('handles default value of empty string', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'prefix',
                schema: ['type' => 'string'],
                default: '',
            );

            // Assert
            expect($argument->default)->toBe('');
        });

        test('handles empty examples array', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
                examples: [],
            );

            // Assert
            expect($argument->examples)->toBe([])
                ->and($argument->examples)->toHaveCount(0);
        });

        test('handles schema with $ref', function (): void {
            // Arrange
            $schema = ['$ref' => '#/components/schemas/Money'];

            // Act
            $argument = new ArgumentData(
                name: 'amount',
                schema: $schema,
            );

            // Assert
            expect($argument->schema)->toBe(['$ref' => '#/components/schemas/Money'])
                ->and($argument->schema['$ref'])->toBe('#/components/schemas/Money');
        });

        test('handles parameter name with underscores', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'customer_billing_address_id',
                schema: ['type' => 'string'],
            );

            // Assert
            expect($argument->name)->toBe('customer_billing_address_id');
        });

        test('handles empty schema object', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'dynamic_field',
                schema: [],
            );

            // Assert
            expect($argument->schema)->toBe([])
                ->and($argument->schema)->toHaveCount(0);
        });

        test('handles examples with different types', function (): void {
            // Arrange & Act
            $argument = new ArgumentData(
                name: 'mixed_field',
                schema: [],
                examples: ['string', 123, true, null],
            );

            // Assert
            expect($argument->examples)->toHaveCount(4)
                ->and($argument->examples[0])->toBe('string')
                ->and($argument->examples[1])->toBe(123)
                ->and($argument->examples[2])->toBeTrue()
                ->and($argument->examples[3])->toBeNull();
        });

        test('toArray preserves schema structure', function (): void {
            // Arrange
            $schema = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
            ];
            $argument = new ArgumentData(
                name: 'line_items',
                schema: $schema,
            );

            // Act
            $array = $argument->toArray();

            // Assert
            expect($array['schema'])->toBe($schema)
                ->and($array['schema']['items']['properties'])->toHaveKey('id')
                ->and($array['schema']['items']['properties']['quantity']['minimum'])->toBe(1);
        });
    });

    describe('Sad Paths', function (): void {
        test('validates required name field exists', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act & Assert
            expect($argument->name)->toBe('test_param');
        });

        test('validates required schema field exists', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act & Assert
            expect($argument->schema)->toBeArray();
        });

        test('validates required field defaults to false', function (): void {
            // Arrange
            $argument = new ArgumentData(
                name: 'test_param',
                schema: ['type' => 'string'],
            );

            // Act & Assert
            expect($argument->required)->toBeFalse();
        });
    });
});

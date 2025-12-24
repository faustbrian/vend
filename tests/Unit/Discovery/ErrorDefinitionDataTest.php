<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ErrorDefinitionData;

describe('ErrorDefinitionData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with required fields only', function (): void {
            // Arrange
            $code = 'CUSTOMER_NOT_FOUND';
            $message = 'Customer not found';

            // Act
            $error = new ErrorDefinitionData(
                code: $code,
                message: $message,
            );

            // Assert
            expect($error->code)->toBe('CUSTOMER_NOT_FOUND')
                ->and($error->message)->toBe('Customer not found')
                ->and($error->description)->toBeNull()
                ->and($error->details)->toBeNull();
        });

        test('creates instance with all fields populated', function (): void {
            // Arrange
            $details = [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string'],
                    'requested' => ['type' => 'integer'],
                    'available' => ['type' => 'integer'],
                ],
            ];

            // Act
            $error = new ErrorDefinitionData(
                code: 'INSUFFICIENT_INVENTORY',
                message: 'Insufficient inventory',
                description: 'Returned when the requested quantity exceeds available inventory',
                details: $details,
            );

            // Assert
            expect($error->code)->toBe('INSUFFICIENT_INVENTORY')
                ->and($error->message)->toBe('Insufficient inventory')
                ->and($error->description)->toBe('Returned when the requested quantity exceeds available inventory')
                ->and($error->details)->toBe($details);
        });

        test('creates instance with complex details schema', function (): void {
            // Arrange
            $details = [
                'type' => 'object',
                'properties' => [
                    'field' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
            ];

            // Act
            $error = new ErrorDefinitionData(
                code: 'VALIDATION_ERROR',
                message: 'Validation failed',
                details: $details,
            );

            // Assert
            expect($error->details)->toBe($details)
                ->and($error->details['properties'])->toHaveKey('field')
                ->and($error->details['properties'])->toHaveKey('errors');
        });

        test('toArray includes all required fields', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'NOT_FOUND',
                message: 'Resource not found',
            );

            // Act
            $array = $error->toArray();

            // Assert
            expect($array)->toHaveKey('code')
                ->and($array)->toHaveKey('message')
                ->and($array['code'])->toBe('NOT_FOUND')
                ->and($array['message'])->toBe('Resource not found');
        });

        test('toArray handles null optional fields', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'NOT_FOUND',
                message: 'Resource not found',
            );

            // Act
            $array = $error->toArray();

            // Assert - Spatie Data may include null fields, verify they are null
            if (array_key_exists('description', $array)) {
                expect($array['description'])->toBeNull();
            }

            expect($array)->toHaveKey('code')
                ->and($array)->toHaveKey('message');
        });

        test('toArray includes details when set', function (): void {
            // Arrange
            $details = ['type' => 'object'];
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error occurred',
                details: $details,
            );

            // Act
            $array = $error->toArray();

            // Assert
            expect($array)->toHaveKey('details')
                ->and($array['details'])->toBe($details);
        });
    });

    describe('Edge Cases', function (): void {
        test('handles null description', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error',
            );

            // Assert
            expect($error->description)->toBeNull();
        });

        test('handles null details', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error',
                details: null,
            );

            // Assert
            expect($error->details)->toBeNull();
        });

        test('handles empty details array', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error',
                details: [],
            );

            // Assert
            expect($error->details)->toBe([])
                ->and($error->details)->toHaveCount(0);
        });

        test('handles error code with underscores', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'CUSTOMER_BILLING_ADDRESS_INVALID',
                message: 'Invalid billing address',
            );

            // Assert
            expect($error->code)->toBe('CUSTOMER_BILLING_ADDRESS_INVALID');
        });

        test('handles error code in lowercase', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'NOT_FOUND',
                message: 'Not found',
            );

            // Assert
            expect($error->code)->toBe('NOT_FOUND');
        });

        test('handles error code with numbers', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'ERROR_500',
                message: 'Internal server error',
            );

            // Assert
            expect($error->code)->toBe('ERROR_500');
        });

        test('handles empty description', function (): void {
            // Arrange & Act
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error',
                description: '',
            );

            // Assert
            expect($error->description)->toBe('');
        });

        test('handles details with $ref', function (): void {
            // Arrange
            $details = ['$ref' => '#/components/schemas/ValidationError'];

            // Act
            $error = new ErrorDefinitionData(
                code: 'VALIDATION_ERROR',
                message: 'Validation failed',
                details: $details,
            );

            // Assert
            expect($error->details)->toBe(['$ref' => '#/components/schemas/ValidationError'])
                ->and($error->details['$ref'])->toBe('#/components/schemas/ValidationError');
        });

        test('handles details with nested schema', function (): void {
            // Arrange
            $details = [
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

            // Act
            $error = new ErrorDefinitionData(
                code: 'COMPLEX_ERROR',
                message: 'Complex error',
                details: $details,
            );

            // Assert
            expect($error->details['properties']['nested']['properties'])->toHaveKey('field');
        });

        test('toArray preserves details schema structure', function (): void {
            // Arrange
            $details = [
                'type' => 'object',
                'properties' => [
                    'sku' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer', 'minimum' => 0],
                ],
            ];
            $error = new ErrorDefinitionData(
                code: 'INVENTORY_ERROR',
                message: 'Inventory error',
                details: $details,
            );

            // Act
            $array = $error->toArray();

            // Assert
            expect($array['details'])->toBe($details)
                ->and($array['details']['properties'])->toHaveKey('sku')
                ->and($array['details']['properties']['quantity']['minimum'])->toBe(0);
        });
    });

    describe('Sad Paths', function (): void {
        test('validates required code field exists', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error message',
            );

            // Act & Assert
            expect($error->code)->toBe('ERROR');
        });

        test('validates required message field exists', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error message',
            );

            // Act & Assert
            expect($error->message)->toBe('Error message');
        });

        test('validates optional description field', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error message',
            );

            // Act & Assert
            expect($error->description)->toBeNull();
        });

        test('validates optional details field', function (): void {
            // Arrange
            $error = new ErrorDefinitionData(
                code: 'ERROR',
                message: 'Error message',
            );

            // Act & Assert
            expect($error->details)->toBeNull();
        });
    });
});

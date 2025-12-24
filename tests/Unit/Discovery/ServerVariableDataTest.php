<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Discovery\ServerVariableData;

describe('ServerVariableData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates instance with default only', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'production',
                description: 'Environment name',
            );

            // Assert
            expect($variable->default)->toBe('production')
                ->and($variable->enum)->toBeNull()
                ->and($variable->description)->toBe('Environment name');
        });

        test('creates instance with enum', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'production',
                enum: ['production', 'staging', 'development'],
                description: 'Deployment environment',
            );

            // Assert
            expect($variable->enum)->toHaveCount(3)
                ->and($variable->enum)->toBe(['production', 'staging', 'development'])
                ->and($variable->default)->toBe('production')
                ->and($variable->description)->toBe('Deployment environment');
        });

        test('creates instance with default in enum list', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'v2',
                enum: ['v1', 'v2', 'v3'],
            );

            // Assert
            expect($variable->default)->toBe('v2')
                ->and($variable->enum)->toContain('v2');
        });

        test('creates instance with minimal fields', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'https',
            );

            // Assert
            expect($variable->default)->toBe('https')
                ->and($variable->enum)->toBeNull()
                ->and($variable->description)->toBeNull();
        });

        test('creates instance with all fields populated', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: '8443',
                enum: ['8080', '8443', '9000'],
                description: 'Server port number',
            );

            // Assert
            expect($variable->default)->toBe('8443')
                ->and($variable->enum)->toHaveCount(3)
                ->and($variable->description)->toBe('Server port number');
        });

        test('toArray includes all fields', function (): void {
            // Arrange
            $variable = new ServerVariableData(
                default: 'staging',
                enum: ['production', 'staging'],
                description: 'Environment',
            );

            // Act
            $array = $variable->toArray();

            // Assert
            expect($array)->toHaveKey('default')
                ->and($array)->toHaveKey('enum')
                ->and($array)->toHaveKey('description')
                ->and($array['default'])->toBe('staging')
                ->and($array['enum'])->toBe(['production', 'staging'])
                ->and($array['description'])->toBe('Environment');
        });

        test('toArray handles null optional fields', function (): void {
            // Arrange
            $variable = new ServerVariableData(
                default: 'prod',
            );

            // Act
            $array = $variable->toArray();

            // Assert
            expect($array)->toHaveKey('default')
                ->and($array['default'])->toBe('prod');
        });

        test('creates instance with single enum value matching default', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'only-option',
                enum: ['only-option'],
            );

            // Assert
            expect($variable->default)->toBe('only-option')
                ->and($variable->enum)->toHaveCount(1)
                ->and($variable->enum)->toContain('only-option');
        });

        test('creates instance with numeric string default', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: '8080',
                enum: ['8080', '8443'],
            );

            // Assert
            expect($variable->default)->toBe('8080')
                ->and($variable->enum)->toContain('8080');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles whitespace in default value', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'production ',
            );

            // Assert
            expect($variable->default)->toBe('production ');
        });

        test('handles enum with duplicate values', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'prod',
                enum: ['prod', 'prod', 'staging'],
            );

            // Assert
            expect($variable->enum)->toHaveCount(3)
                ->and($variable->default)->toBe('prod');
        });

        test('handles long description', function (): void {
            // Arrange
            $longDescription = str_repeat('This is a very long description. ', 100);

            // Act
            $variable = new ServerVariableData(
                default: 'test',
                description: $longDescription,
            );

            // Assert
            expect($variable->description)->toBe($longDescription);
        });

        test('handles special characters in default', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'api.v2.beta',
                enum: ['api.v1', 'api.v2.beta'],
            );

            // Assert
            expect($variable->default)->toBe('api.v2.beta')
                ->and($variable->enum)->toContain('api.v2.beta');
        });

        test('handles case-sensitive enum matching', function (): void {
            // Arrange & Act
            $variable = new ServerVariableData(
                default: 'Production',
                enum: ['Production', 'Staging', 'Development'],
            );

            // Assert
            expect($variable->default)->toBe('Production')
                ->and($variable->enum)->toContain('Production');
        });
    });

    describe('Sad Paths', function (): void {
        test('rejects default not in enum', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'invalid',
                enum: ['production', 'staging'],
            ))->toThrow(InvalidArgumentException::class, 'must be one of the enum values');
        });

        test('rejects empty default', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: '',
            ))->toThrow(InvalidArgumentException::class, 'cannot be empty');
        });

        test('rejects whitespace-only default', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: '   ',
            ))->toThrow(InvalidArgumentException::class, 'cannot be empty');
        });

        test('rejects empty enum array', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: [],
            ))->toThrow(InvalidArgumentException::class, 'enum cannot be empty');
        });

        test('rejects non-string enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: '1',
                enum: [1, 2, 3],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('rejects mixed type enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: ['test', 123, true],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('rejects null in enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: ['test', null, 'other'],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('rejects boolean in enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'true',
                enum: ['true', true, 'false'],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('rejects array in enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: ['test', ['nested'], 'other'],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('rejects object in enum values', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: ['test', (object) ['key' => 'value'], 'other'],
            ))->toThrow(InvalidArgumentException::class, 'must be string');
        });

        test('error message includes all enum values', function (): void {
            // Arrange
            $enum = ['production', 'staging', 'development'];

            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'invalid',
                enum: $enum,
            ))->toThrow(
                InvalidArgumentException::class,
                "Default value 'invalid' must be one of the enum values: production, staging, development",
            );
        });

        test('error message identifies index of invalid enum value', function (): void {
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: ['test', 'valid', 123, 'another'],
            ))->toThrow(InvalidArgumentException::class, 'enum[2] must be string, got integer');
        });

        test('validates enum before checking default is in enum', function (): void {
            // This test ensures that enum validation happens first
            // Act & Assert
            expect(fn (): ServerVariableData => new ServerVariableData(
                default: 'test',
                enum: [123, 'test'],
            ))->toThrow(InvalidArgumentException::class, 'enum[0] must be string, got integer');
        });
    });
});

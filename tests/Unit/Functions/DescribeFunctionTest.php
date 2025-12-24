<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\ServerInterface;
use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Exceptions\EmptyArrayException;
use Cline\Forrst\Extensions\Discovery\Functions\DescribeFunction;
use Cline\Forrst\Facades\Server as ServerFacade;
use Cline\Forrst\Repositories\FunctionRepository;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;

describe('DescribeFunction', function (): void {
    beforeEach(function (): void {
        // Create a mock ServerInterface and swap the facade
        $mockServer = Mockery::mock(ServerInterface::class);
        ServerFacade::swap($mockServer);
    });

    describe('Happy Paths', function (): void {
        describe('getName()', function (): void {
            test('returns standard Forrst discovery function name', function (): void {
                // Arrange
                $function = new DescribeFunction();

                // Act
                $result = $function->getUrn();

                // Assert
                expect($result)->toBe('urn:cline:forrst:ext:discovery:fn:describe');
            });
        });

        describe('getVersion()', function (): void {
            test('returns default version', function (): void {
                // Arrange
                $function = new DescribeFunction();

                // Act
                $result = $function->getVersion();

                // Assert
                expect($result)->toBe('1.0.0');
            });
        });

        describe('getSummary()', function (): void {
            test('returns description of discovery function', function (): void {
                // Arrange
                $function = new DescribeFunction();

                // Act
                $result = $function->getSummary();

                // Assert
                expect($result)->toBe('Returns the Forrst Discovery document describing this service');
            });
        });

        describe('getResult()', function (): void {
            test('returns ResultDescriptorData with Forrst Discovery schema', function (): void {
                // Arrange
                $function = new DescribeFunction();

                // Act
                $result = $function->getResult();

                // Assert
                expect($result)->toBeInstanceOf(ResultDescriptorData::class);

                $array = $result->toArray();
                expect($array)->toHaveKey('schema')
                    ->and($array['schema'])->toHaveKey('type')
                    ->and($array['schema']['type'])->toBe('object')
                    ->and($array)->toHaveKey('description')
                    ->and($array['description'])->toBe('Complete discovery document or single function descriptor');
            });
        });

        describe('getArguments()', function (): void {
            test('returns arguments for function and version filtering', function (): void {
                // Arrange
                $function = new DescribeFunction();

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result)->toBeArray()
                    ->and($result)->toHaveCount(2);

                // Check function argument
                expect($result[0]->name)->toBe('function')
                    ->and($result[0]->required)->toBeFalse()
                    ->and($result[0]->description)->toBe('Specific function to describe');

                // Check version argument
                expect($result[1]->name)->toBe('version')
                    ->and($result[1]->required)->toBeFalse()
                    ->and($result[1]->description)->toBe('Specific version (with function)');
            });
        });

        describe('handle()', function (): void {
            test('generates complete Forrst Discovery document', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:function');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Test function summary');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result)->toBeArray()
                    ->and($result)->toHaveKey('forrst')
                    ->and($result['forrst'])->toBe('0.1.0')
                    ->and($result)->toHaveKey('discovery')
                    ->and($result['discovery'])->toBe('0.1.0')
                    ->and($result)->toHaveKey('info')
                    ->and($result['info'])->toHaveKey('title')
                    ->and($result['info'])->toHaveKey('version')
                    ->and($result['info'])->toHaveKey('license')
                    ->and($result)->toHaveKey('servers')
                    ->and($result)->toHaveKey('functions')
                    ->and($result)->toHaveKey('components');
            });

            test('includes server configuration with environment and URL', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:minimal');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Minimal test function');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Production Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('2.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/api/forrst');

                App::shouldReceive('environment')->andReturn('production');
                URL::shouldReceive('to')->with('/api/forrst')->andReturn('https://example.com/api/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result['servers'])->toBeArray()
                    ->and($result['servers'])->toHaveCount(1)
                    ->and($result['servers'][0])->toHaveKey('name')
                    ->and($result['servers'][0]['name'])->toBe('production')
                    ->and($result['servers'][0])->toHaveKey('url')
                    ->and($result['servers'][0]['url'])->toBe('https://example.com/api/forrst');
            });

            test('aggregates errors from multiple sources', function (): void {
                // Arrange
                $mockFunction1 = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:function:one');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Function One');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([
                        ['code' => 'CUSTOM_ERROR_1', 'message' => 'Custom error 1'],
                    ]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $mockFunction2 = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:function:two');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Function Two');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([
                        ['code' => 'CUSTOM_ERROR_2', 'message' => 'Custom error 2'],
                    ]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction1, $mockFunction2]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result['functions'])->toHaveCount(2)
                    ->and($result['functions'][0]['errors'])->toBeArray()
                    ->and($result['functions'][1]['errors'])->toBeArray();
            });

            test('assembles components section with errors', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:minimal');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Minimal test function');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result['components'])->toHaveKey('errors')
                    ->and($result['components']['errors'])->not()->toBeEmpty();
            });

            test('includes all standard Forrst error codes', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:minimal');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Minimal test function');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert - check standard Forrst error codes are present
                $errorCodes = array_keys($result['components']['errors']);

                expect($errorCodes)->toContain('PARSE_ERROR')
                    ->and($errorCodes)->toContain('INVALID_REQUEST')
                    ->and($errorCodes)->toContain('FUNCTION_NOT_FOUND')
                    ->and($errorCodes)->toContain('INVALID_ARGUMENTS')
                    ->and($errorCodes)->toContain('SCHEMA_VALIDATION_FAILED')
                    ->and($errorCodes)->toContain('INTERNAL_ERROR')
                    ->and($errorCodes)->toContain('UNAVAILABLE')
                    ->and($errorCodes)->toContain('UNAUTHORIZED')
                    ->and($errorCodes)->toContain('FORBIDDEN')
                    ->and($errorCodes)->toContain('RATE_LIMITED');
            });

            test('collects all functions from repository', function (): void {
                // Arrange
                $mockFunction1 = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:app:forrst:fn:users:list');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('List all users');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $mockFunction2 = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:app:forrst:fn:users:get');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Get a single user');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction1, $mockFunction2]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result['functions'])->toHaveCount(2)
                    ->and($result['functions'][0]['name'])->toBe('urn:app:forrst:fn:users:list')
                    ->and($result['functions'][0]['summary'])->toBe('List all users')
                    ->and($result['functions'][1]['name'])->toBe('urn:app:forrst:fn:users:get')
                    ->and($result['functions'][1]['summary'])->toBe('Get a single user');
            });

            test('generates valid Forrst Discovery document structure', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:minimal');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Minimal test function');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert - DiscoveryData ensures proper structure
                expect($result)->toBeArray()
                    ->and($result)->toHaveKey('forrst')
                    ->and($result)->toHaveKey('discovery')
                    ->and($result)->toHaveKey('info')
                    ->and($result)->toHaveKey('servers')
                    ->and($result)->toHaveKey('functions')
                    ->and($result)->toHaveKey('components');
            });

            test('includes function version in descriptor', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:function');
                    $mock->shouldReceive('getVersion')->andReturn('2.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Test function');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert
                expect($result['functions'][0])->toHaveKey('version')
                    ->and($result['functions'][0]['version'])->toBe('2.0.0');
            });
        });
    });

    describe('Edge Cases', function (): void {
        describe('filterRecursive()', function (): void {
            test('removes null values from array', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'key1' => 'value1',
                    'key2' => null,
                    'key3' => 'value3',
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe(['key1' => 'value1', 'key3' => 'value3']);
            });

            test('removes empty strings from array', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'key1' => 'value1',
                    'key2' => '',
                    'key3' => 'value3',
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe(['key1' => 'value1', 'key3' => 'value3']);
            });

            test('preserves boolean false values', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'key1' => 'value1',
                    'key2' => false,
                    'key3' => true,
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe([
                    'key1' => 'value1',
                    'key2' => false,
                    'key3' => true,
                ]);
            });

            test('preserves numeric zero values', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'key1' => 1,
                    'key2' => 0,
                    'key3' => 2,
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe([
                    'key1' => 1,
                    'key2' => 0,
                    'key3' => 2,
                ]);
            });

            test('recursively filters nested arrays', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'level1' => [
                        'key1' => 'value1',
                        'key2' => null,
                        'level2' => [
                            'key3' => 'value3',
                            'key4' => '',
                            'key5' => false,
                        ],
                    ],
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe([
                    'level1' => [
                        'key1' => 'value1',
                        'level2' => [
                            'key3' => 'value3',
                            'key5' => false,
                        ],
                    ],
                ]);
            });

            test('removes empty arrays after filtering', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'key1' => 'value1',
                    'key2' => [],
                    'key3' => [
                        'nested' => null,
                    ],
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe(['key1' => 'value1']);
            });

            test('handles deeply nested arrays correctly', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'l1' => [
                        'l2' => [
                            'l3' => [
                                'key1' => 'value1',
                                'key2' => null,
                                'key3' => false,
                            ],
                        ],
                    ],
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe([
                    'l1' => [
                        'l2' => [
                            'l3' => [
                                'key1' => 'value1',
                                'key3' => false,
                            ],
                        ],
                    ],
                ]);
            });

            test('handles mixed types correctly', function (): void {
                // Arrange
                $function = new DescribeFunction();
                $reflection = new ReflectionClass($function);
                $filterMethod = $reflection->getMethod('filterRecursive');

                $input = [
                    'string' => 'value',
                    'null' => null,
                    'false' => false,
                    'zero' => 0,
                    'empty' => '',
                    'array' => ['nested' => 'value'],
                    'true' => true,
                    'number' => 42,
                ];

                // Act
                $result = $filterMethod->invoke(null, $input);

                // Assert
                expect($result)->toBe([
                    'string' => 'value',
                    'false' => false,
                    'zero' => 0,
                    'array' => ['nested' => 'value'],
                    'true' => true,
                    'number' => 42,
                ]);
            });
        });

        describe('handle() with empty data', function (): void {
            test('handles server with no registered functions', function (): void {
                // Arrange
                $repository = new FunctionRepository([]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Empty Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('0.0.1');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act & Assert
                expect(fn () => $function->handle())
                    ->toThrow(EmptyArrayException::class);
            });
        });
    });

    describe('Sad Paths', function (): void {
        describe('handle() error scenarios', function (): void {
            test('handles function with null result gracefully', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:function');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Test');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert - should not throw exception
                expect($result)->toBeArray()
                    ->and($result['functions'][0])->not()->toHaveKey('result');
            });

            test('handles functions with empty error arrays', function (): void {
                // Arrange
                $mockFunction = mock(FunctionInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('getUrn')->andReturn('urn:cline:forrst:fn:test:function');
                    $mock->shouldReceive('getVersion')->andReturn('1.0.0');
                    $mock->shouldReceive('getSummary')->andReturn('Test');
                    $mock->shouldReceive('getArguments')->andReturn([]);
                    $mock->shouldReceive('getResult')->andReturn(null);
                    $mock->shouldReceive('getErrors')->andReturn([]);
                    $mock->shouldReceive('isDiscoverable')->andReturn(true);
                    $mock->shouldReceive('getDescription')->andReturn(null);
                    $mock->shouldReceive('getTags')->andReturn(null);
                    $mock->shouldReceive('getQuery')->andReturn(null);
                    $mock->shouldReceive('getDeprecated')->andReturn(null);
                    $mock->shouldReceive('getSideEffects')->andReturn(null);
                    $mock->shouldReceive('getExamples')->andReturn(null);
                    $mock->shouldReceive('getLinks')->andReturn(null);
                    $mock->shouldReceive('getSimulations')->andReturn(null);
                    $mock->shouldReceive('getExternalDocs')->andReturn(null);
                    $mock->shouldReceive('getExtensions')->andReturn(null);
                });

                $repository = new FunctionRepository([$mockFunction]);

                ServerFacade::shouldReceive('getFunctionRepository')->andReturn($repository);
                ServerFacade::shouldReceive('getName')->andReturn('Test Server');
                ServerFacade::shouldReceive('getVersion')->andReturn('1.0.0');
                ServerFacade::shouldReceive('getRoutePath')->andReturn('/forrst');

                App::shouldReceive('environment')->andReturn('testing');
                URL::shouldReceive('to')->with('/forrst')->andReturn('http://localhost/forrst');

                $function = new DescribeFunction();

                // Act
                $result = $function->handle();

                // Assert - should still have standard errors
                expect($result['functions'][0]['errors'])->toBeArray()
                    ->and(count($result['functions'][0]['errors']))->toBeGreaterThan(0);
            });
        });
    });
});

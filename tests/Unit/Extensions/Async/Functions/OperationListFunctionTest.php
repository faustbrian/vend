<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Carbon\CarbonImmutable;
use Cline\Forrst\Contracts\OperationRepositoryInterface;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Extensions\Async\Functions\OperationListFunction;
use Mockery\MockInterface;

describe('OperationListFunction', function (): void {
    describe('Happy Paths', function (): void {
        describe('getName()', function (): void {
            test('returns standard Forrst operation list function name', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getUrn();

                // Assert
                expect($result)->toBe('urn:cline:forrst:ext:async:fn:list');
            });
        });

        describe('getVersion()', function (): void {
            test('returns default version', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getVersion();

                // Assert
                expect($result)->toBe('1.0.0');
            });
        });

        describe('getSummary()', function (): void {
            test('returns description of operation list function', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getSummary();

                // Assert
                expect($result)->toBe('List operations for the current caller');
            });
        });

        describe('getArguments()', function (): void {
            test('returns four optional filter arguments', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result)->toBeArray()
                    ->and($result)->toHaveCount(4);
            });

            test('status argument is optional string with enum', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result[0])->toBeInstanceOf(ArgumentData::class)
                    ->and($result[0]->name)->toBe('status')
                    ->and($result[0]->schema['type'])->toBe('string')
                    ->and($result[0]->required)->toBeFalse()
                    ->and($result[0]->description)->toBe('Filter by status')
                    ->and($result[0]->schema)->toHaveKey('enum')
                    ->and($result[0]->schema['enum'])->toContain('pending')
                    ->and($result[0]->schema['enum'])->toContain('processing')
                    ->and($result[0]->schema['enum'])->toContain('completed')
                    ->and($result[0]->schema['enum'])->toContain('failed')
                    ->and($result[0]->schema['enum'])->toContain('cancelled');
            });

            test('function argument is optional string', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result[1])->toBeInstanceOf(ArgumentData::class)
                    ->and($result[1]->name)->toBe('function')
                    ->and($result[1]->schema['type'])->toBe('string')
                    ->and($result[1]->required)->toBeFalse()
                    ->and($result[1]->description)->toBe('Filter by function name');
            });

            test('limit argument is optional integer with default 50', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result[2])->toBeInstanceOf(ArgumentData::class)
                    ->and($result[2]->name)->toBe('limit')
                    ->and($result[2]->schema['type'])->toBe('integer')
                    ->and($result[2]->required)->toBeFalse()
                    ->and($result[2]->description)->toBe('Max results (default 50)')
                    ->and($result[2]->schema)->toHaveKey('default')
                    ->and($result[2]->schema['default'])->toBe(50)
                    ->and($result[2]->schema)->toHaveKey('minimum')
                    ->and($result[2]->schema['minimum'])->toBe(1)
                    ->and($result[2]->schema)->toHaveKey('maximum')
                    ->and($result[2]->schema['maximum'])->toBe(100);
            });

            test('cursor argument is optional string', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result[3])->toBeInstanceOf(ArgumentData::class)
                    ->and($result[3]->name)->toBe('cursor')
                    ->and($result[3]->schema['type'])->toBe('string')
                    ->and($result[3]->required)->toBeFalse()
                    ->and($result[3]->description)->toBe('Pagination cursor');
            });
        });

        describe('getResult()', function (): void {
            test('returns ResultDescriptorData with operation list schema', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                expect($result)->toBeInstanceOf(ResultDescriptorData::class);

                $array = $result->toArray();
                expect($array)->toHaveKey('schema')
                    ->and($array['schema'])->toHaveKey('type')
                    ->and($array['schema']['type'])->toBe('object')
                    ->and($array)->toHaveKey('description')
                    ->and($array['description'])->toBe('Operation list response');
            });

            test('schema requires operations array', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                $array = $result->toArray();
                expect($array['schema'])->toHaveKey('required')
                    ->and($array['schema']['required'])->toContain('operations');
            });

            test('schema defines operations as array of objects', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationListFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                $array = $result->toArray();
                expect($array['schema']['properties']['operations'])->toHaveKey('type')
                    ->and($array['schema']['properties']['operations']['type'])->toBe('array')
                    ->and($array['schema']['properties']['operations']['items']['type'])->toBe('object');
            });
        });

        describe('__invoke()', function (): void {
            test('returns empty operations list when no operations exist', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => [],
                        'next_cursor' => null,
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->toHaveKey('operations')
                    ->and($result['operations'])->toBeArray()
                    ->and($result['operations'])->toHaveCount(0)
                    ->and($result)->not()->toHaveKey('next_cursor');
            });

            test('returns list of operations', function (): void {
                // Arrange
                $operations = [
                    new OperationData(
                        'op-1',
                        'urn:app:forrst:fn:users:create',
                        status: OperationStatus::Completed,
                        startedAt: CarbonImmutable::now()->subMinutes(10),
                    ),
                    new OperationData(
                        'op-2',
                        'data.export',
                        status: OperationStatus::Processing,
                        progress: 0.75,
                        startedAt: CarbonImmutable::now()->subMinutes(5),
                    ),
                ];

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operations): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => $operations,
                        'next_cursor' => null,
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['operations'])->toHaveCount(2)
                    ->and($result['operations'][0]['id'])->toBe('op-1')
                    ->and($result['operations'][0]['function'])->toBe('urn:app:forrst:fn:users:create')
                    ->and($result['operations'][0]['status'])->toBe('completed')
                    ->and($result['operations'][1]['id'])->toBe('op-2')
                    ->and($result['operations'][1]['function'])->toBe('data.export')
                    ->and($result['operations'][1]['status'])->toBe('processing');
            });

            test('passes status filter to repository', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with('completed', null, 50, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['status' => 'completed'],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with('completed', null, 50, null);
            });

            test('passes function filter to repository', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, 'urn:app:forrst:fn:users:create', 50, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['function' => 'urn:app:forrst:fn:users:create'],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, 'urn:app:forrst:fn:users:create', 50, null);
            });

            test('passes limit to repository', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, null, 25, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['limit' => 25],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, null, 25, null);
            });

            test('uses default limit of 50 when not provided', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, null, 50, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, null, 50, null);
            });

            test('passes cursor to repository', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, null, 50, 'cursor-abc123')
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['cursor' => 'cursor-abc123'],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, null, 50, 'cursor-abc123');
            });

            test('includes next_cursor when more results available', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => [
                            new OperationData('op-1', 'test', status: OperationStatus::Pending),
                        ],
                        'next_cursor' => 'cursor-next-page',
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->toHaveKey('next_cursor')
                    ->and($result['next_cursor'])->toBe('cursor-next-page');
            });

            test('excludes next_cursor when no more results', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => [
                            new OperationData('op-1', 'test', status: OperationStatus::Pending),
                        ],
                        'next_cursor' => null,
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->not()->toHaveKey('next_cursor');
            });

            test('passes multiple filters to repository', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with('processing', 'data.export', 10, 'cursor-123')
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    [
                        'status' => 'processing',
                    'started_at' => now(),
                        'function' => 'data.export',
                        'limit' => 10,
                        'cursor' => 'cursor-123',
                    ],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with('processing', 'data.export', 10, 'cursor-123');
            });
        });
    });

    describe('Edge Cases', function (): void {
        describe('operation data conversion', function (): void {
            test('converts all operation fields to array', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op-full',
                    'urn:cline:forrst:fn:test:function',
                    version: '2.0',
                    status: OperationStatus::Completed,
                    progress: 1.0,
                    result: ['data' => 'result'],
                    startedAt: CarbonImmutable::now()->subMinute(),
                    completedAt: CarbonImmutable::now(),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => [$operation],
                        'next_cursor' => null,
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['operations'][0])->toHaveKey('id')
                    ->and($result['operations'][0])->toHaveKey('function')
                    ->and($result['operations'][0])->toHaveKey('version')
                    ->and($result['operations'][0])->toHaveKey('status')
                    ->and($result['operations'][0])->toHaveKey('progress')
                    ->and($result['operations'][0])->toHaveKey('result')
                    ->and($result['operations'][0])->toHaveKey('started_at')
                    ->and($result['operations'][0])->toHaveKey('completed_at');
            });

            test('handles large number of operations', function (): void {
                // Arrange
                $operations = array_map(
                    fn (int $i): OperationData => new OperationData(
                        'op-'.$i,
                        'urn:cline:forrst:fn:test:function',
                        status: OperationStatus::Pending,
                    ),
                    range(1, 100),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operations): void {
                    $mock->shouldReceive('list')->andReturn([
                        'operations' => $operations,
                        'next_cursor' => null,
                    ]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['operations'])->toHaveCount(100);
            });
        });

        describe('limit edge values', function (): void {
            test('respects minimum limit of 1', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, null, 1, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['limit' => 1],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, null, 1, null);
            });

            test('respects maximum limit of 100', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')
                        ->with(null, null, 100, null)
                        ->andReturn(['operations' => [], 'next_cursor' => null]);
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:list',
                    ['limit' => 100],
                );
                $function->setRequest($request);

                // Act
                $function();

                // Assert - verified by mock expectations
                $repository->shouldHaveReceived('list')->with(null, null, 100, null);
            });
        });
    });

    describe('Sad Paths', function (): void {
        describe('repository errors', function (): void {
            test('propagates repository exception', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('list')->andThrow(
                        new RuntimeException('Database connection failed'),
                    );
                });

                $function = new OperationListFunction($repository);
                $request = RequestObjectData::asRequest('urn:cline:forrst:ext:async:fn:list', []);
                $function->setRequest($request);

                // Act & Assert
                expect(fn (): array => $function())->toThrow(RuntimeException::class);
            });
        });
    });
});

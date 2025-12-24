<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Carbon\CarbonImmutable;
use Cline\Forrst\Contracts\OperationRepositoryInterface;
use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Exceptions\NotFoundException;
use Cline\Forrst\Exceptions\OperationNotFoundException;
use Cline\Forrst\Extensions\Async\Functions\OperationStatusFunction;
use Mockery\MockInterface;

describe('OperationStatusFunction', function (): void {
    describe('Happy Paths', function (): void {
        describe('getName()', function (): void {
            test('returns standard Forrst operation status function name', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getUrn();

                // Assert
                expect($result)->toBe('urn:cline:forrst:ext:async:fn:status');
            });
        });

        describe('getVersion()', function (): void {
            test('returns default version', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getVersion();

                // Assert
                expect($result)->toBe('1.0.0');
            });
        });

        describe('getSummary()', function (): void {
            test('returns description of operation status function', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getSummary();

                // Assert
                expect($result)->toBe('Check status of an async operation');
            });
        });

        describe('getArguments()', function (): void {
            test('returns operation_id argument', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result)->toBeArray()
                    ->and($result)->toHaveCount(1);
            });

            test('operation_id argument is required string', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getArguments();

                // Assert
                expect($result[0])->toBeInstanceOf(ArgumentData::class)
                    ->and($result[0]->name)->toBe('operation_id')
                    ->and($result[0]->schema['type'])->toBe('string')
                    ->and($result[0]->required)->toBeTrue()
                    ->and($result[0]->description)->toBe('Unique operation identifier');
            });
        });

        describe('getResult()', function (): void {
            test('returns ResultDescriptorData with operation status schema', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                expect($result)->toBeInstanceOf(ResultDescriptorData::class);

                $array = $result->toArray();
                expect($array)->toHaveKey('schema')
                    ->and($array['schema'])->toHaveKey('type')
                    ->and($array['schema']['type'])->toBe('object')
                    ->and($array)->toHaveKey('description')
                    ->and($array['description'])->toBe('Operation status response');
            });

            test('schema defines status enum with operation states', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                $array = $result->toArray();
                expect($array['schema']['properties']['status'])->toHaveKey('enum')
                    ->and($array['schema']['properties']['status']['enum'])
                    ->toContain('pending')
                    ->and($array['schema']['properties']['status']['enum'])
                    ->toContain('processing')
                    ->and($array['schema']['properties']['status']['enum'])
                    ->toContain('completed')
                    ->and($array['schema']['properties']['status']['enum'])
                    ->toContain('failed')
                    ->and($array['schema']['properties']['status']['enum'])
                    ->toContain('cancelled');
            });

            test('schema requires id and status fields', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getResult();

                // Assert
                $array = $result->toArray();
                expect($array['schema'])->toHaveKey('required')
                    ->and($array['schema']['required'])->toContain('id')
                    ->and($array['schema']['required'])->toContain('status');
            });
        });

        describe('getErrors()', function (): void {
            test('returns AsyncOperationNotFound error definition', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class);
                $function = new OperationStatusFunction($repository);

                // Act
                $result = $function->getErrors();

                // Assert
                expect($result)->toBeArray()
                    ->and($result)->toHaveCount(1)
                    ->and($result[0])->toBeInstanceOf(ErrorDefinitionData::class)
                    ->and($result[0]->code)->toBe(ErrorCode::AsyncOperationNotFound->value)
                    ->and($result[0]->message)->toBe('Operation not found');
            });
        });

        describe('__invoke()', function (): void {
            test('returns operation data for pending operation', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_123456789012345678901',
                    'urn:app:forrst:fn:users:create',
                    version: '1.0.0',
                    status: OperationStatus::Pending,
                    startedAt: CarbonImmutable::now(),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_123456789012345678901', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_123456789012345678901'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->toHaveKey('id')
                    ->and($result['id'])->toBe('op_123456789012345678901')
                    ->and($result)->toHaveKey('status')
                    ->and($result['status'])->toBe('pending')
                    ->and($result)->toHaveKey('function')
                    ->and($result['function'])->toBe('urn:app:forrst:fn:users:create');
            });

            test('returns operation data for processing operation with progress', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_456789012345678901234',
                    'data.export',
                    version: '1.0.0',
                    status: OperationStatus::Processing,
                    progress: 0.65,
                    startedAt: CarbonImmutable::now(),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_456789012345678901234', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_456789012345678901234'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['status'])->toBe('processing')
                    ->and($result)->toHaveKey('progress')
                    ->and($result['progress'])->toBe(0.65);
            });

            test('returns operation data for completed operation with result', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_789012345678901234567',
                    'orders.process',
                    version: '1.0.0',
                    status: OperationStatus::Completed,
                    result: ['order_id' => 'ord-123', 'total' => 99.99],
                    startedAt: CarbonImmutable::now()->subMinutes(5),
                    completedAt: CarbonImmutable::now(),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_789012345678901234567', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_789012345678901234567'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['status'])->toBe('completed')
                    ->and($result)->toHaveKey('result')
                    ->and($result['result'])->toHaveKey('order_id')
                    ->and($result['result']['order_id'])->toBe('ord-123')
                    ->and($result)->toHaveKey('completed_at');
            });

            test('returns operation data for failed operation with errors', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_999012345678901234567',
                    'payment.process',
                    version: '1.0.0',
                    status: OperationStatus::Failed,
                    errors: [
                        ErrorData::from([
                            'code' => 'PAYMENT_DECLINED',
                            'message' => 'Card was declined',
                        ]),
                    ],
                    startedAt: CarbonImmutable::now()->subMinutes(2),
                    completedAt: CarbonImmutable::now(),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_999012345678901234567', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_999012345678901234567'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['status'])->toBe('failed')
                    ->and($result)->toHaveKey('errors')
                    ->and($result['errors'])->toBeArray()
                    ->and($result['errors'])->toHaveCount(1)
                    ->and($result['errors'][0]['code'])->toBe('PAYMENT_DECLINED');
            });

            test('returns operation data for cancelled operation', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_111234567890123456789',
                    'backup.create',
                    version: '1.0.0',
                    status: OperationStatus::Cancelled,
                    startedAt: CarbonImmutable::now()->subMinutes(10),
                    cancelledAt: CarbonImmutable::now()->subMinutes(5),
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_111234567890123456789', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_111234567890123456789'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result['status'])->toBe('cancelled')
                    ->and($result)->toHaveKey('cancelled_at');
            });
        });
    });

    describe('Edge Cases', function (): void {
        describe('operation data conversion', function (): void {
            test('handles operation with minimal fields', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_min0123456789012345',
                    'urn:cline:forrst:fn:test:function',
                    status: OperationStatus::Pending,
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_min0123456789012345', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_min0123456789012345'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->toHaveKey('id')
                    ->and($result)->toHaveKey('function')
                    ->and($result)->toHaveKey('status')
                    ->and($result)->not()->toHaveKey('version')
                    ->and($result)->not()->toHaveKey('progress')
                    ->and($result)->not()->toHaveKey('result');
            });

            test('handles operation with all optional fields', function (): void {
                // Arrange
                $operation = new OperationData(
                    'op_full012345678901234',
                    'urn:cline:forrst:fn:test:function',
                    version: '2.0',
                    status: OperationStatus::Completed,
                    progress: 1.0,
                    result: ['data' => 'result'],
                    startedAt: CarbonImmutable::now()->subMinute(),
                    completedAt: CarbonImmutable::now(),
                    metadata: ['key' => 'value'],
                );

                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock) use ($operation): void {
                    $mock->shouldReceive('find')->with('op_full012345678901234', null)->andReturn($operation);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_full012345678901234'],
                );
                $function->setRequest($request);

                // Act
                $result = $function();

                // Assert
                expect($result)->toHaveKey('version')
                    ->and($result)->toHaveKey('progress')
                    ->and($result)->toHaveKey('result')
                    ->and($result)->toHaveKey('started_at')
                    ->and($result)->toHaveKey('completed_at')
                    ->and($result)->toHaveKey('metadata');
            });
        });
    });

    describe('Sad Paths', function (): void {
        describe('operation not found', function (): void {
            test('throws NotFoundException when operation does not exist', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('find')->with('op_000000000000000000000001', null)->andReturn(null);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_000000000000000000000001'],
                );
                $function->setRequest($request);

                // Act & Assert
                expect(fn (): array => $function())->toThrow(OperationNotFoundException::class);
            });

            test('exception message includes operation ID', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('find')->with('op_000000000000000000000002', null)->andReturn(null);
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_000000000000000000000002'],
                );
                $function->setRequest($request);

                // Act & Assert
                try {
                    $function();
                    expect(true)->toBeFalse(); // Should not reach here
                } catch (NotFoundException $notFoundException) {
                    expect($notFoundException->getMessage())->toContain('op_000000000000000000000002');
                }
            });
        });

        describe('repository errors', function (): void {
            test('propagates repository exception', function (): void {
                // Arrange
                $repository = mock(OperationRepositoryInterface::class, function (MockInterface $mock): void {
                    $mock->shouldReceive('find')->andThrow(
                        new RuntimeException('Database connection failed'),
                    );
                });

                $function = new OperationStatusFunction($repository);
                $request = RequestObjectData::asRequest(
                    'urn:cline:forrst:ext:async:fn:status',
                    ['operation_id' => 'op_123456789012345678901'],
                );
                $function->setRequest($request);

                // Act & Assert
                expect(fn (): array => $function())->toThrow(RuntimeException::class);
            });
        });
    });
});

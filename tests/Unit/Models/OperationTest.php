<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Carbon\CarbonImmutable;
use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Models\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('Operation Model', function (): void {
    describe('Happy Paths', function (): void {
        test('converts model to OperationData with all fields', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op-123',
                'function' => 'urn:cline:forrst:fn:test:function',
                'version' => '1.0.0',
                'status' => 'completed',
                'progress' => 1.0,
                'result' => ['data' => 'value'],
                'errors' => [
                    [
                        'code' => 'internal_error',
                        'message' => 'Something went wrong',
                    ],
                ],
                'metadata' => ['key' => 'value'],
                'started_at' => CarbonImmutable::parse('2025-01-01 10:00:00'),
                'completed_at' => CarbonImmutable::parse('2025-01-01 10:05:00'),
                'cancelled_at' => null,
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data)->toBeInstanceOf(OperationData::class);
            expect($data->id)->toBe('op-123');
            expect($data->function)->toBe('urn:cline:forrst:fn:test:function');
            expect($data->version)->toBe('1.0.0');
            expect($data->status)->toBe(OperationStatus::Completed);
            expect($data->progress)->toBe(1.0);
            expect($data->result)->toBe(['data' => 'value']);
            expect($data->errors)->toHaveCount(1);
            expect($data->errors[0])->toBeInstanceOf(ErrorData::class);
            expect($data->errors[0]->code)->toBe('internal_error');
            expect($data->metadata)->toBe(['key' => 'value']);
            expect($data->startedAt)->toBeInstanceOf(CarbonImmutable::class);
            expect($data->completedAt)->toBeInstanceOf(CarbonImmutable::class);
        });

        test('converts model to OperationData with null errors', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op-456',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'pending',
                'errors' => null,
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data->errors)->toBeNull();
        });

        test('creates model from OperationData with all fields', function (): void {
            // Arrange
            $operationData = new OperationData(
                id: 'op-789',
                function: 'urn:cline:forrst:fn:test:function',
                version: '2.0.0',
                status: OperationStatus::Processing,
                progress: 0.5,
                result: ['result' => 'data'],
                errors: [
                    new ErrorData(
                        code: ErrorCode::InternalError,
                        message: 'Test error',
                    ),
                ],
                startedAt: CarbonImmutable::parse('2025-01-02 10:00:00'),
                completedAt: null,
                cancelledAt: null,
                metadata: ['meta' => 'data'],
            );

            // Act
            $operation = Operation::fromOperationData($operationData);

            // Assert
            expect($operation)->toBeInstanceOf(Operation::class);
            expect($operation->id)->toBe('op-789');
            expect($operation->function)->toBe('urn:cline:forrst:fn:test:function');
            expect($operation->version)->toBe('2.0.0');
            expect($operation->status)->toBe(OperationStatus::Processing->value);
            expect($operation->progress)->toBe(0.5);
            expect($operation->result)->toBe(['result' => 'data']);
            expect($operation->errors)->toHaveCount(1);
            expect($operation->errors[0]['code'])->toBe('INTERNAL_ERROR');
            expect($operation->metadata)->toBe(['meta' => 'data']);
            expect($operation->started_at)->toBeInstanceOf(CarbonImmutable::class);
            expect($operation->completed_at)->toBeNull();
        });

        test('creates model from OperationData with null errors', function (): void {
            // Arrange
            $operationData = new OperationData(
                id: 'op-999',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Pending,
            );

            // Act
            $operation = Operation::fromOperationData($operationData);

            // Assert
            expect($operation->errors)->toBeNull();
        });

        test('uses string primary key', function (): void {
            // Arrange & Act
            $operation = new Operation(['id' => 'custom-id-123']);

            // Assert
            expect($operation->getKeyType())->toBe('string');
            expect($operation->getIncrementing())->toBeFalse();
        });

        test('scopes filter by status', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.func',
                'status' => 'pending',
            ]);
            Operation::query()->create([
                'id' => 'op-2',
                'function' => 'test.func',
                'status' => 'completed',
            ]);
            Operation::query()->create([
                'id' => 'op-3',
                'function' => 'test.func',
                'status' => 'pending',
            ]);

            // Act
            $pending = Operation::query()->withStatus('pending')->get();
            $completed = Operation::query()->withStatus('completed')->get();

            // Assert
            expect($pending)->toHaveCount(2);
            expect($completed)->toHaveCount(1);
        });

        test('scopes filter by function', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.function1',
                'status' => 'pending',
            ]);
            Operation::query()->create([
                'id' => 'op-2',
                'function' => 'test.function2',
                'status' => 'pending',
            ]);
            Operation::query()->create([
                'id' => 'op-3',
                'function' => 'test.function1',
                'status' => 'completed',
            ]);

            // Act
            $function1 = Operation::query()->forFunction('test.function1')->get();
            $function2 = Operation::query()->forFunction('test.function2')->get();

            // Assert
            expect($function1)->toHaveCount(2);
            expect($function2)->toHaveCount(1);
        });

        test('scopes filter by caller', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.func',
                'status' => 'pending',
                'caller_id' => 'caller-1',
            ]);
            Operation::query()->create([
                'id' => 'op-2',
                'function' => 'test.func',
                'status' => 'pending',
                'caller_id' => 'caller-2',
            ]);
            Operation::query()->create([
                'id' => 'op-3',
                'function' => 'test.func',
                'status' => 'pending',
                'caller_id' => 'caller-1',
            ]);

            // Act
            $caller1Ops = Operation::query()->forCaller('caller-1')->get();
            $caller2Ops = Operation::query()->forCaller('caller-2')->get();

            // Assert
            expect($caller1Ops)->toHaveCount(2);
            expect($caller2Ops)->toHaveCount(1);
        });

        test('scopes find expired operations', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => now()->subDays(1),
            ]);
            Operation::query()->create([
                'id' => 'op-2',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => now()->addDays(1),
            ]);
            Operation::query()->create([
                'id' => 'op-3',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => null,
            ]);

            // Act
            $expired = Operation::query()->expired()->get();

            // Assert
            expect($expired)->toHaveCount(1);
            expect($expired->first()->id)->toBe('op-1');
        });

        test('combines multiple scopes', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.function1',
                'status' => 'pending',
                'caller_id' => 'caller-1',
            ]);
            Operation::query()->create([
                'id' => 'op-2',
                'function' => 'test.function2',
                'status' => 'pending',
                'caller_id' => 'caller-1',
            ]);
            Operation::query()->create([
                'id' => 'op-3',
                'function' => 'test.function1',
                'status' => 'completed',
                'caller_id' => 'caller-1',
            ]);

            // Act
            $result = Operation::query()->forCaller('caller-1')
                ->forFunction('test.function1')
                ->withStatus('pending')
                ->get();

            // Assert
            expect($result)->toHaveCount(1);
            expect($result->first()->id)->toBe('op-1');
        });
    });

    describe('Edge Cases', function (): void {
        test('handles empty result array', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op-empty',
                'function' => 'test.func',
                'status' => 'pending',
                'result' => [],
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data->result)->toBe([]);
        });

        test('handles zero progress', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op-zero',
                'function' => 'test.func',
                'status' => 'processing',
                    'started_at' => now(),
                'progress' => 0.0,
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data->progress)->toBe(0.0);
        });

        test('handles complete progress', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op-complete',
                'function' => 'test.func',
                'status' => 'processing',
                    'started_at' => now(),
                'progress' => 1.0,
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data->progress)->toBe(1.0);
        });

        test('handles multiple errors in array', function (): void {
            // Arrange
            $operation = new Operation([
                'id' => 'op_multierror',
                'function' => 'test.func',
                'status' => 'failed',
                'errors' => [
                    ['code' => 'error_1', 'message' => 'First error'],
                    ['code' => 'error_2', 'message' => 'Second error'],
                    ['code' => 'error_3', 'message' => 'Third error'],
                ],
            ]);

            // Act
            $data = $operation->toOperationData();

            // Assert
            expect($data->errors)->toHaveCount(3);
            expect($data->errors[0]->code)->toBe('error_1');
            expect($data->errors[1]->code)->toBe('error_2');
            expect($data->errors[2]->code)->toBe('error_3');
        });

        test('casts arrays correctly', function (): void {
            // Arrange & Act
            $operation = Operation::query()->create([
                'id' => 'op-cast',
                'function' => 'test.func',
                'status' => 'pending',
                'result' => ['key' => 'value'],
                'metadata' => ['meta' => 'data'],
            ]);

            $operation->refresh();

            // Assert
            expect($operation->result)->toBeArray();
            expect($operation->metadata)->toBeArray();
            expect($operation->result)->toBe(['key' => 'value']);
            expect($operation->metadata)->toBe(['meta' => 'data']);
        });

        test('casts datetime fields to immutable', function (): void {
            // Arrange
            $now = now();

            // Act
            $operation = Operation::query()->create([
                'id' => 'op-datetime',
                'function' => 'test.func',
                'status' => 'completed',
                'started_at' => $now,
                'completed_at' => $now,
            ]);

            $operation->refresh();

            // Assert
            expect($operation->started_at)->toBeInstanceOf(CarbonImmutable::class);
            expect($operation->completed_at)->toBeInstanceOf(CarbonImmutable::class);
            expect($operation->created_at)->not->toBeNull();
            expect($operation->updated_at)->not->toBeNull();
        });

        test('scopes handle no matching records', function (): void {
            // Arrange
            Operation::query()->create([
                'id' => 'op-1',
                'function' => 'test.func',
                'status' => 'pending',
            ]);

            // Act
            $completed = Operation::query()->withStatus('completed')->get();
            $otherFunction = Operation::query()->forFunction('other.function')->get();
            $expired = Operation::query()->expired()->get();

            // Assert
            expect($completed)->toHaveCount(0);
            expect($otherFunction)->toHaveCount(0);
            expect($expired)->toHaveCount(0);
        });
    });

    describe('Sad Paths', function (): void {
        test('requires id field', function (): void {
            // Arrange & Act & Assert
            expect(function (): void {
                Operation::query()->create([
                    'function' => 'test.func',
                    'status' => 'pending',
                ]);
            })->toThrow(Exception::class);
        });

        test('requires function field', function (): void {
            // Arrange & Act & Assert
            expect(function (): void {
                Operation::query()->create([
                    'id' => 'op-missing-function',
                    'status' => 'pending',
                ]);
            })->toThrow(Exception::class);
        });

        test('allows creating operation without explicit status', function (): void {
            // Arrange & Act
            // Note: The database has a default value for status
            $operation = new Operation([
                'id' => 'op-default-status',
                'function' => 'test.func',
            ]);

            // Assert
            // When creating a new instance without saving, the attribute won't have the DB default
            // This test verifies that status is optional in the fillable array
            expect($operation->getAttribute('status'))->toBeNull();
        });
    });
});

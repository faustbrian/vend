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
use Cline\Forrst\Repositories\DatabaseOperationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

describe('DatabaseOperationRepository', function (): void {
    describe('Happy Paths', function (): void {
        test('finds existing operation by id', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_find1',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'pending',
            ]);

            // Act
            $result = $repository->find('op_find1');

            // Assert
            expect($result)->toBeInstanceOf(OperationData::class);
            expect($result->id)->toBe('op_find1');
            expect($result->function)->toBe('urn:cline:forrst:fn:test:function');
            expect($result->status)->toBe(OperationStatus::Pending);
        });

        test('saves new operation with default retention', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            $operationData = new OperationData(
                id: 'op_new1',
                function: 'urn:cline:forrst:fn:test:function',
                version: '1.0.0',
                status: OperationStatus::Pending,
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_new1');
            expect($operation)->not->toBeNull();
            expect($operation->id)->toBe('op_new1');
            expect($operation->function)->toBe('urn:cline:forrst:fn:test:function');
            expect($operation->version)->toBe('1.0.0');
            expect($operation->status)->toBe(OperationStatus::Pending->value);
            expect($operation->expires_at)->not->toBeNull();
            expect(abs($operation->expires_at->diffInDays(now(), false)))->toBeGreaterThanOrEqual(29);
            expect(abs($operation->expires_at->diffInDays(now(), false)))->toBeLessThanOrEqual(30);
        });

        test('saves new operation with custom retention', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository(retentionDays: 7);
            $operationData = new OperationData(
                id: 'op_new2',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Pending,
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_new2');
            expect($operation->expires_at)->not->toBeNull();
            expect(abs($operation->expires_at->diffInDays(now(), false)))->toBeGreaterThanOrEqual(6);
            expect(abs($operation->expires_at->diffInDays(now(), false)))->toBeLessThanOrEqual(7);
        });

        test('updates existing operation status and progress', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_update1',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'pending',
                'progress' => null,
                'expires_at' => now()->addDays(30),
            ]);

            $operationData = new OperationData(
                id: 'op_update1',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                progress: 0.5,
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_update1');
            expect($operation->status)->toBe(OperationStatus::Processing->value);
            expect($operation->progress)->toBe(0.5);
        });

        test('updates existing operation with result', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_update2',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'processing',
                    'started_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);

            $operationData = new OperationData(
                id: 'op_update2',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Completed,
                result: ['data' => 'value', 'count' => 42],
                completedAt: CarbonImmutable::now(),
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_update2');
            expect($operation->status)->toBe(OperationStatus::Completed->value);
            expect($operation->result)->toBe(['data' => 'value', 'count' => 42]);
            expect($operation->completed_at)->not->toBeNull();
        });

        test('updates existing operation with errors', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_update3',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'processing',
                    'started_at' => now(),
                'expires_at' => now()->addDays(30),
            ]);

            $operationData = new OperationData(
                id: 'op_update3',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Failed,
                errors: [
                    new ErrorData(
                        code: ErrorCode::InternalError,
                        message: 'Operation failed',
                    ),
                ],
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_update3');
            expect($operation->status)->toBe(OperationStatus::Failed->value);
            expect($operation->errors)->toHaveCount(1);
            expect($operation->errors[0]['code'])->toBe('INTERNAL_ERROR');
            expect($operation->errors[0]['message'])->toBe('Operation failed');
        });

        test('updates existing operation with metadata', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_update4',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'pending',
                'expires_at' => now()->addDays(30),
            ]);

            $operationData = new OperationData(
                id: 'op_update4',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                metadata: ['user_id' => '123', 'retry_count' => 2],
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op_update4');
            expect($operation->metadata)->toBe(['user_id' => '123', 'retry_count' => 2]);
        });

        test('deletes operation by id', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_delete1',
                'function' => 'urn:cline:forrst:fn:test:function',
                'status' => 'completed',
            ]);

            // Act
            $repository->delete('op_delete1');

            // Assert
            expect(Operation::query()->find('op_delete1'))->toBeNull();
        });

        test('lists all operations with default limit', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            for ($i = 1; $i <= 10; ++$i) {
                Operation::query()->create([
                    'id' => sprintf('op_list_%019d', $i),
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                ]);
            }

            // Act
            $result = $repository->list();

            // Assert
            expect($result['operations'])->toHaveCount(10);
            expect($result['next_cursor'])->toBeNull();
        });

        test('lists operations with status filter', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-2', 'function' => 'test.func', 'status' => 'completed']);
            Operation::query()->create(['id' => 'op-3', 'function' => 'test.func', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-4', 'function' => 'test.func', 'status' => 'failed']);

            // Act
            $result = $repository->list(status: OperationStatus::Pending->value);

            // Assert
            expect($result['operations'])->toHaveCount(2);
            expect($result['operations'][0]->status)->toBe(OperationStatus::Pending);
            expect($result['operations'][1]->status)->toBe(OperationStatus::Pending);
        });

        test('lists operations with function filter', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.function1', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-2', 'function' => 'test.function2', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-3', 'function' => 'test.function1', 'status' => 'completed']);

            // Act
            $result = $repository->list(function: 'test.function1');

            // Assert
            expect($result['operations'])->toHaveCount(2);
            expect($result['operations'][0]->function)->toBe('test.function1');
            expect($result['operations'][1]->function)->toBe('test.function1');
        });

        test('lists operations with both status and function filters', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.function1', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-2', 'function' => 'test.function2', 'status' => 'pending']);
            Operation::query()->create(['id' => 'op-3', 'function' => 'test.function1', 'status' => 'completed']);

            // Act
            $result = $repository->list(status: OperationStatus::Pending->value, function: 'test.function1');

            // Assert
            expect($result['operations'])->toHaveCount(1);
            expect($result['operations'][0]->id)->toBe('op-1');
        });

        test('lists operations with custom limit', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            for ($i = 1; $i <= 10; ++$i) {
                Operation::query()->create([
                    'id' => sprintf('op_limit_%018d', $i),
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                ]);
            }

            // Act
            $result = $repository->list(limit: 5);

            // Assert
            expect($result['operations'])->toHaveCount(5);
            expect($result['next_cursor'])->not->toBeNull();
        });

        test('lists operations with pagination cursor', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            // Use sequential IDs to ensure proper cursor-based pagination
            // The repository uses id for cursor comparison, not created_at
            for ($i = 10; $i >= 1; --$i) {
                Operation::query()->create([
                    'id' => sprintf('op_page_%019d', $i),
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                ]);
            }

            // Act - Get first page
            $firstPage = $repository->list(limit: 5);
            expect($firstPage['operations'])->toHaveCount(5);
            expect($firstPage['next_cursor'])->not->toBeNull();

            // Act - Get second page
            $secondPage = $repository->list(limit: 5, cursor: $firstPage['next_cursor']);

            // Assert
            expect($secondPage['operations'])->toHaveCount(5);

            // Verify we got different operations (the cursor filters by id)
            $firstIds = array_map(fn (OperationData $op): string => $op->id, $firstPage['operations']);
            $secondIds = array_map(fn (OperationData $op): string => $op->id, $secondPage['operations']);

            // Should have some different IDs between pages
            expect($firstIds)->not->toBe($secondIds);
        });

        test('lists operations ordered by created_at desc', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-old', 'function' => 'test.func', 'status' => 'pending']);
            Sleep::sleep(1);
            Operation::query()->create(['id' => 'op-new', 'function' => 'test.func', 'status' => 'pending']);

            // Act
            $result = $repository->list();

            // Assert
            expect($result['operations'][0]->id)->toBe('op-new');
            expect($result['operations'][1]->id)->toBe('op-old');
        });

        test('lists operations for specific caller', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-1']);
            Operation::query()->create(['id' => 'op-2', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-2']);
            Operation::query()->create(['id' => 'op-3', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-1']);

            // Act
            $result = $repository->listForCaller('caller-1');

            // Assert
            expect($result['operations'])->toHaveCount(2);
            expect($result['operations'][0]->id)->toBeIn(['op-1', 'op-3']);
            expect($result['operations'][1]->id)->toBeIn(['op-1', 'op-3']);
        });

        test('lists operations for caller with status filter', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-1']);
            Operation::query()->create(['id' => 'op-2', 'function' => 'test.func', 'status' => 'completed', 'caller_id' => 'caller-1']);
            Operation::query()->create(['id' => 'op-3', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-1']);

            // Act
            $result = $repository->listForCaller('caller-1', status: OperationStatus::Pending->value);

            // Assert
            expect($result['operations'])->toHaveCount(2);
        });

        test('lists operations for caller with pagination', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            for ($i = 1; $i <= 10; ++$i) {
                Operation::query()->create([
                    'id' => sprintf('op_caller_%017d', $i),
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                    'caller_id' => 'caller-1',
                ]);
            }

            // Act
            $firstPage = $repository->listForCaller('caller-1', limit: 5);

            // Assert
            expect($firstPage['operations'])->toHaveCount(5);
            expect($firstPage['next_cursor'])->not->toBeNull();
        });

        test('cleans up expired operations', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op_expired1',
                'function' => 'test.func',
                'status' => 'completed',
                'expires_at' => now()->subDays(1),
            ]);
            Operation::query()->create([
                'id' => 'op_expired2',
                'function' => 'test.func',
                'status' => 'completed',
                'expires_at' => now()->subHours(1),
            ]);
            Operation::query()->create([
                'id' => 'op-active',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => now()->addDays(1),
            ]);

            // Act
            $deletedCount = $repository->cleanupExpired();

            // Assert
            expect($deletedCount)->toBe(2);
            expect(Operation::query()->find('op_expired1'))->toBeNull();
            expect(Operation::query()->find('op_expired2'))->toBeNull();
            expect(Operation::query()->find('op-active'))->not->toBeNull();
        });
    });

    describe('Edge Cases', function (): void {
        test('returns null for non-existent operation', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            // Act
            $result = $repository->find('non-existent-id');

            // Assert
            expect($result)->toBeNull();
        });

        test('deleting non-existent operation does not throw error', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            // Act & Assert
            expect(fn () => $repository->delete('non-existent-id'))->not->toThrow(Exception::class);
        });

        test('lists empty result when no operations match filters', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending']);

            // Act
            $result = $repository->list(status: OperationStatus::Completed->value);

            // Assert
            expect($result['operations'])->toBeEmpty();
            expect($result['next_cursor'])->toBeNull();
        });

        test('lists empty result for caller with no operations', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending', 'caller_id' => 'caller-1']);

            // Act
            $result = $repository->listForCaller('caller-2');

            // Assert
            expect($result['operations'])->toBeEmpty();
            expect($result['next_cursor'])->toBeNull();
        });

        test('cleanup returns zero when no expired operations exist', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op-active',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => now()->addDays(1),
            ]);

            // Act
            $deletedCount = $repository->cleanupExpired();

            // Assert
            expect($deletedCount)->toBe(0);
        });

        test('handles operations without expires_at during cleanup', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create([
                'id' => 'op-no-expiry',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => null,
            ]);
            Operation::query()->create([
                'id' => 'op-expired',
                'function' => 'test.func',
                'status' => 'completed',
                'expires_at' => now()->subDays(1),
            ]);

            // Act
            $deletedCount = $repository->cleanupExpired();

            // Assert
            expect($deletedCount)->toBe(1);
            expect(Operation::query()->find('op-no-expiry'))->not->toBeNull();
        });

        test('handles invalid cursor gracefully', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            Operation::query()->create(['id' => 'op-1', 'function' => 'test.func', 'status' => 'pending']);

            // Act
            $result = $repository->list(cursor: 'invalid-cursor');

            // Assert
            expect($result['operations'])->toHaveCount(1);
        });

        test('saves operation with all optional fields', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            $operationData = new OperationData(
                id: 'op-full',
                function: 'urn:cline:forrst:fn:test:function',
                version: '2.0.0',
                status: OperationStatus::Completed,
                progress: 1.0,
                result: ['final' => 'result'],
                errors: null,
                startedAt: CarbonImmutable::now()->subMinutes(5),
                completedAt: CarbonImmutable::now(),
                cancelledAt: null,
                metadata: ['duration_ms' => 5_000],
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op-full');
            expect($operation->version)->toBe('2.0.0');
            expect($operation->progress)->toBe(1.0);
            expect($operation->result)->toBe(['final' => 'result']);
            expect($operation->started_at)->not->toBeNull();
            expect($operation->completed_at)->not->toBeNull();
            expect($operation->metadata)->toBe(['duration_ms' => 5_000]);
        });

        test('preserves expires_at when updating existing operation', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();
            $expiresAt = now()->addDays(15);
            Operation::query()->create([
                'id' => 'op-preserve',
                'function' => 'test.func',
                'status' => 'pending',
                'expires_at' => $expiresAt,
            ]);

            $operationData = new OperationData(
                id: 'op-preserve',
                function: 'urn:cline:forrst:fn:test:func',
                status: OperationStatus::Processing,
                progress: 0.5,
            );

            // Act
            $repository->save($operationData);

            // Assert
            $operation = Operation::query()->find('op-preserve');
            expect($operation->expires_at->timestamp)->toBe($expiresAt->timestamp);
        });

        test('returns correct next_cursor at exact limit boundary', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            for ($i = 1; $i <= 5; ++$i) {
                Operation::query()->create([
                    'id' => sprintf('op_exact_%018d', $i),
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                ]);
            }

            // Act
            $result = $repository->list(limit: 5);

            // Assert
            expect($result['operations'])->toHaveCount(5);
            expect($result['next_cursor'])->toBeNull();
        });
    });

    describe('Sad Paths', function (): void {
        test('list with invalid limit uses provided limit', function (): void {
            // Arrange
            $repository = new DatabaseOperationRepository();

            for ($i = 1; $i <= 5; ++$i) {
                Operation::query()->create([
                    'id' => 'op-'.$i,
                    'function' => 'urn:cline:forrst:fn:test:function',
                    'status' => 'pending',
                ]);
            }

            // Act
            $result = $repository->list(limit: 0);

            // Assert
            // Note: The repository doesn't validate limit, it just uses it
            // A limit of 0 would return 0 results (limit + 1 = 1, take 0)
            expect($result['operations'])->toHaveCount(0);
        });
    });
});

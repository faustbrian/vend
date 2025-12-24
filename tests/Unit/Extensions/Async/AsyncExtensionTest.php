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
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Extensions\Async\AsyncExtension;
use Cline\Forrst\Extensions\Async\Exceptions\OperationNotFoundException;
use Cline\Forrst\Extensions\ExtensionUrn;
use Mockery as m;

describe('AsyncExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('returns correct URN constant', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Async->value);
        });

        test('isPreferred returns true when preferred option is set', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = ['preferred' => true];

            // Act
            $result = $extension->isPreferred($options);

            // Assert
            expect($result)->toBeTrue();
        });

        test('getCallbackUrl returns callback URL from options', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = ['callback_url' => 'https://example.com/callback'];

            // Act
            $result = $extension->getCallbackUrl($options);

            // Assert
            expect($result)->toBe('https://example.com/callback');
        });

        test('isErrorFatal returns true', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);

            // Act
            $result = $extension->isErrorFatal();

            // Assert
            expect($result)->toBeTrue();
        });

        test('createAsyncOperation generates operation and response with pending status', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation instanceof OperationData
                    && $operation->status === OperationStatus::Pending
                    && $operation->function === 'urn:cline:forrst:fn:test:function'
                    && str_starts_with($operation->id, 'op_'));

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value'], '1');
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value, ['preferred' => true]);

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            expect($result)->toHaveKey('response')
                ->and($result)->toHaveKey('operation')
                ->and($result['operation']->status)->toBe(OperationStatus::Pending)
                ->and($result['response']->id)->toBe($request->id)
                ->and($result['response']->extensions)->toHaveCount(1)
                ->and($result['response']->extensions[0]->data)->toHaveKey('operation_id')
                ->and($result['response']->extensions[0]->data)->toHaveKey('status')
                ->and($result['response']->extensions[0]->data)->toHaveKey('poll')
                ->and($result['response']->extensions[0]->data)->toHaveKey('retry_after');
        });

        test('createAsyncOperation includes callback URL in operation metadata when provided', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['callback_url'] === 'https://example.com/callback');

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value']);
            $extensionData = ExtensionData::request(
                ExtensionUrn::Async->value,
                ['callback_url' => 'https://example.com/callback'],
            );

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            expect($result['operation']->metadata)->toHaveKey('callback_url')
                ->and($result['operation']->metadata['callback_url'])->toBe('https://example.com/callback');
        });

        test('createAsyncOperation includes original request ID in operation metadata', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')->once();

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value']);
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            expect($result['operation']->metadata)->toHaveKey('original_request_id')
                ->and($result['operation']->metadata['original_request_id'])->toBe($request->id);
        });

        test('createAsyncOperation uses custom retry seconds in response', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')->once();

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value']);
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData, retrySeconds: 10);

            // Assert
            expect($result['response']->extensions[0]->data['retry_after']['value'])->toBe(10)
                ->and($result['response']->extensions[0]->data['retry_after']['unit'])->toBe('second');
        });

        test('createAsyncOperation includes poll function specification in response', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')->once();

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value']);
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            $poll = $result['response']->extensions[0]->data['poll'];
            expect($poll)->toHaveKey('function')
                ->and($poll)->toHaveKey('version')
                ->and($poll)->toHaveKey('arguments')
                ->and($poll['function'])->toBe('urn:cline:forrst:ext:async:fn:status')
                ->and($poll['version'])->toBe('1')
                ->and($poll['arguments'])->toHaveKey('operation_id');
        });

        test('markProcessing transitions operation to processing status with start timestamp', function (): void {
            // Arrange
            $pendingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Pending,
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($pendingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->status === OperationStatus::Processing
                    && $operation->startedAt instanceof CarbonImmutable);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->markProcessing('op_123456789012345678901234');

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('markProcessing sets initial progress when provided', function (): void {
            // Arrange
            $pendingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Pending,
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($pendingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->progress === 0.1);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->markProcessing('op_123456789012345678901234', 0.1);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('complete transitions operation to completed status with result and timestamp', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->status === OperationStatus::Completed
                    && $operation->result === ['data' => 'success']
                    && $operation->progress === 1.0
                    && $operation->completedAt instanceof CarbonImmutable);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->complete('op_123456789012345678901234', ['data' => 'success']);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('fail transitions operation to failed status with errors and timestamp', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $errors = [
                new ErrorData(ErrorCode::InvalidRequest, 'Invalid input'),
            ];

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->status === OperationStatus::Failed
                    && $operation->errors === $errors
                    && $operation->completedAt instanceof CarbonImmutable);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->fail('op_123456789012345678901234', $errors);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('updateProgress updates operation progress and preserves existing data', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                progress: 0.5,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->progress === 0.75
                    && $operation->status === OperationStatus::Processing);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->updateProgress('op_123456789012345678901234', 0.75);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('updateProgress includes progress message in metadata when provided', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                metadata: ['key' => 'value'],
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['progress_message'] === 'Processing items...'
                    && $operation->metadata['key'] === 'value');

            $extension = new AsyncExtension($repository);

            // Act
            $extension->updateProgress('op_123456789012345678901234', 0.5, 'Processing items...');

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });
    });

    describe('Edge Cases', function (): void {
        test('isPreferred returns false when preferred option is missing', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = [];

            // Act
            $result = $extension->isPreferred($options);

            // Assert
            expect($result)->toBeFalse();
        });

        test('isPreferred returns false when preferred is not boolean true', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = ['preferred' => 'true'];

            // Act
            $result = $extension->isPreferred($options);

            // Assert
            expect($result)->toBeFalse();
        });

        test('isPreferred handles null options gracefully', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);

            // Act
            $result = $extension->isPreferred(null);

            // Assert
            expect($result)->toBeFalse();
        });

        test('getCallbackUrl returns null when not specified', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = [];

            // Act
            $result = $extension->getCallbackUrl($options);

            // Assert
            expect($result)->toBeNull();
        });

        test('getCallbackUrl handles null options', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);

            // Act
            $result = $extension->getCallbackUrl(null);

            // Assert
            expect($result)->toBeNull();
        });

        test('markProcessing throws exception when operation not found', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_000000000000000000000001')
                ->andReturn(null);
            $repository->shouldNotReceive('save');

            $extension = new AsyncExtension($repository);

            // Act & Assert
            expect(fn (): mixed => $extension->markProcessing('op_000000000000000000000001'))
                ->toThrow(OperationNotFoundException::class);
        });

        test('complete throws exception when operation not found', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_000000000000000000000001')
                ->andReturn(null);
            $repository->shouldNotReceive('save');

            $extension = new AsyncExtension($repository);

            // Act & Assert
            expect(fn (): mixed => $extension->complete('op_000000000000000000000001', 'result'))
                ->toThrow(OperationNotFoundException::class);
        });

        test('fail throws exception when operation not found', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_000000000000000000000001')
                ->andReturn(null);
            $repository->shouldNotReceive('save');

            $extension = new AsyncExtension($repository);

            // Act & Assert
            expect(fn (): mixed => $extension->fail('op_000000000000000000000001', []))
                ->toThrow(OperationNotFoundException::class);
        });

        test('updateProgress throws exception when operation not found', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_000000000000000000000001')
                ->andReturn(null);
            $repository->shouldNotReceive('save');

            $extension = new AsyncExtension($repository);

            // Act & Assert
            expect(fn (): mixed => $extension->updateProgress('op_000000000000000000000001', 0.5))
                ->toThrow(OperationNotFoundException::class);
        });

        test('updateProgress clamps progress above 1.0 to 1.0', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->progress === 1.0);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->updateProgress('op_123456789012345678901234', 1.5);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('updateProgress clamps progress below 0.0 to 0.0', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->progress === 0.0);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->updateProgress('op_123456789012345678901234', -0.5);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('updateProgress handles null metadata in existing operation', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                metadata: null,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['progress_message'] === 'Working...');

            $extension = new AsyncExtension($repository);

            // Act
            $extension->updateProgress('op_123456789012345678901234', 0.3, 'Working...');

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('createAsyncOperation merges custom metadata with default metadata', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['custom_key'] === 'custom_value'
                    && $operation->metadata['original_request_id'] !== null);

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value']);
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result = $extension->createAsyncOperation(
                $request,
                $extensionData,
                metadata: ['custom_key' => 'custom_value'],
            );

            // Assert
            expect($result['operation']->metadata)->toHaveKey('custom_key')
                ->and($result['operation']->metadata)->toHaveKey('original_request_id');
        });

        test('createAsyncOperation generates unique operation IDs', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')->times(3);

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function');
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result1 = $extension->createAsyncOperation($request, $extensionData);
            $result2 = $extension->createAsyncOperation($request, $extensionData);
            $result3 = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            expect($result1['operation']->id)->not->toBe($result2['operation']->id)
                ->and($result2['operation']->id)->not->toBe($result3['operation']->id)
                ->and($result1['operation']->id)->not->toBe($result3['operation']->id)
                ->and($result1['operation']->id)->toStartWith('op_')
                ->and($result2['operation']->id)->toStartWith('op_')
                ->and($result3['operation']->id)->toStartWith('op_');
        });

        test('createAsyncOperation includes function version in operation', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')->andReturn(null);
            $repository->shouldReceive('save')->once();

            $extension = new AsyncExtension($repository);
            $request = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value'], '2');
            $extensionData = ExtensionData::request(ExtensionUrn::Async->value);

            // Act
            $result = $extension->createAsyncOperation($request, $extensionData);

            // Assert
            expect($result['operation']->version)->toBe('2');
        });

        test('markProcessing preserves existing metadata', function (): void {
            // Arrange
            $pendingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Pending,
                metadata: ['key' => 'value'],
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($pendingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['key'] === 'value');

            $extension = new AsyncExtension($repository);

            // Act
            $extension->markProcessing('op_123456789012345678901234');

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('complete preserves existing metadata', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                metadata: ['key' => 'value'],
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['key'] === 'value');

            $extension = new AsyncExtension($repository);

            // Act
            $extension->complete('op_123456789012345678901234', ['result' => 'data']);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('fail preserves existing metadata', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                metadata: ['key' => 'value'],
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->metadata['key'] === 'value');

            $extension = new AsyncExtension($repository);

            // Act
            $extension->fail('op_123456789012345678901234', [new ErrorData(ErrorCode::InvalidRequest, 'Error')]);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('fail preserves progress from processing operation', function (): void {
            // Arrange
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                progress: 0.6,
                startedAt: CarbonImmutable::parse('2024-01-15 10:00:00'),
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->progress === 0.6);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->fail('op_123456789012345678901234', [new ErrorData(ErrorCode::InvalidRequest, 'Error')]);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('complete preserves startedAt timestamp from processing operation', function (): void {
            // Arrange
            $startedAt = CarbonImmutable::parse('2024-01-15 10:00:00');
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: $startedAt,
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->startedAt === $startedAt);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->complete('op_123456789012345678901234', 'result');

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('fail preserves startedAt timestamp from processing operation', function (): void {
            // Arrange
            $startedAt = CarbonImmutable::parse('2024-01-15 10:00:00');
            $processingOperation = new OperationData(
                id: 'op_123456789012345678901234',
                function: 'urn:cline:forrst:fn:test:function',
                status: OperationStatus::Processing,
                startedAt: $startedAt,
            );

            $repository = m::mock(OperationRepositoryInterface::class);
            $repository->shouldReceive('find')
                ->once()
                ->with('op_123456789012345678901234')
                ->andReturn($processingOperation);
            $repository->shouldReceive('save')
                ->once()
                ->withArgs(fn ($operation): bool => $operation->startedAt === $startedAt);

            $extension = new AsyncExtension($repository);

            // Act
            $extension->fail('op_123456789012345678901234', [new ErrorData(ErrorCode::InvalidRequest, 'Error')]);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });
    });

    describe('Sad Paths', function (): void {
        test('isPreferred returns false for false value', function (): void {
            // Arrange
            $repository = m::mock(OperationRepositoryInterface::class);
            $extension = new AsyncExtension($repository);
            $options = ['preferred' => false];

            // Act
            $result = $extension->isPreferred($options);

            // Assert
            expect($result)->toBeFalse();
        });
    });
});

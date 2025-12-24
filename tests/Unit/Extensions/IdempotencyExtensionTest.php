<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Data\CallData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Exceptions\MustBePositiveException;
use Cline\Forrst\Extensions\ExtensionUrn;
use Cline\Forrst\Extensions\IdempotencyExtension;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

describe('IdempotencyExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('getUrn returns correct URN constant', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Idempotency->value)
                ->and($urn)->toBe('urn:cline:forrst:ext:idempotency');
        });

        test('getIdempotencyKey extracts key from options', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['key' => 'idempotency-key-123'];

            // Act
            $key = $extension->getIdempotencyKey($options);

            // Assert
            expect($key)->toBe('idempotency-key-123');
        });

        test('getIdempotencyKey returns null when key missing', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = [];

            // Act
            $key = $extension->getIdempotencyKey($options);

            // Assert
            expect($key)->toBeNull();
        });

        test('getTtl returns default TTL when not specified', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache, 3_600);

            // Act
            $ttl = $extension->getTtl([]);

            // Assert
            expect($ttl)->toBe(3_600);
        });

        test('getTtl converts seconds correctly', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 120, 'unit' => 'second']];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(120);
        });

        test('getTtl converts minutes to seconds', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 5, 'unit' => 'minute']];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(300);
        });

        test('getTtl converts hours to seconds', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 2, 'unit' => 'hour']];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(7_200);
        });

        test('getTtl converts days to seconds', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 1, 'unit' => 'day']];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(86_400);
        });

        test('onExecutingFunction does not short-circuit when no cached result exists', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(true);

            Cache::shouldReceive('lock')->once()->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0001',
                call: new CallData(function: 'createUser', arguments: ['name' => 'John']),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'test-key']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onExecutingFunction returns error when key is missing', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0002',
                call: new CallData(function: 'test'),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, []);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->isFailed())->toBeTrue()
                ->and($event->isPropagationStopped())->toBeTrue();

            $error = $result->getFirstError();
            expect($error->code)->toBe(ErrorCode::InvalidArguments->value)
                ->and($error->message)->toBe('Idempotency key is required');
        });

        test('onFunctionExecuted caches response and adds extension metadata', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->once(); // cache entry

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(true);
            $lock->shouldReceive('release')->once();

            Cache::shouldReceive('lock')->once()->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0003',
                call: new CallData(function: 'createUser', arguments: ['name' => 'Jane']),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'test-key']);
            $response = ResponseData::success(['id' => 123], '01JFEX0003');

            // Set up context via onExecutingFunction first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert
            $result = $executedEvent->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->extensions)->toHaveCount(1);

            $ext = $result->extensions[0];
            expect($ext->urn)->toBe(ExtensionUrn::Idempotency->value)
                ->and($ext->data)->toHaveKey('key', 'test-key')
                ->and($ext->data)->toHaveKey('status', IdempotencyExtension::STATUS_PROCESSED)
                ->and($ext->data)->toHaveKey('original_request_id', '01JFEX0003')
                ->and($ext->data)->toHaveKey('expires_at');
        });

        test('onFunctionExecuted returns response unchanged when context is null', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0004',
                call: new CallData(function: 'test'),
            );
            $response = ResponseData::success(['data' => 'test'], '01JFEX0004');
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, []);

            // Act - skip onExecutingFunction so context is null
            $event = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($event);

            // Assert
            expect($event->getResponse())->toBe($response);
        });
    });

    describe('Edge Cases', function (): void {
        test('getIdempotencyKey returns null for null options', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);

            // Act
            $key = $extension->getIdempotencyKey(null);

            // Assert
            expect($key)->toBeNull();
        });

        test('getTtl handles unknown unit by treating as seconds', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 100, 'unit' => 'unknown']];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(100);
        });

        test('getTtl handles missing value', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['unit' => 'second']];

            // Act & Assert
            expect(fn () => $extension->getTtl($options))
                ->toThrow(MustBePositiveException::class);
        });

        test('getTtl handles missing unit', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 60]];

            // Act
            $ttl = $extension->getTtl($options);

            // Assert
            expect($ttl)->toBe(60);
        });

        test('onExecutingFunction acquires processing lock', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(true);

            Cache::shouldReceive('lock')
                ->once()
                ->with(Mockery::pattern('/^forrst_idempotency:.*:lock$/'), 30)
                ->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0005',
                call: new CallData(function: 'test', arguments: ['data' => 'test']),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'lock-test']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            expect($event->getResponse())->toBeNull()
                ->and($event->isPropagationStopped())->toBeFalse();
        });

        test('onExecutingFunction returns processing response when lock exists', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(false); // Lock acquisition fails

            Cache::shouldReceive('lock')->once()->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0006',
                call: new CallData(function: 'test'),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'locked-key']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->isFailed())->toBeTrue()
                ->and($event->isPropagationStopped())->toBeTrue();

            $error = $result->getFirstError();
            expect($error->code)->toBe(ErrorCode::IdempotencyProcessing->value)
                ->and($error->message)->toContain('still processing');
        });

        test('getTtl handles zero value', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $extension = new IdempotencyExtension($cache);
            $options = ['ttl' => ['value' => 0, 'unit' => 'second']];

            // Act & Assert
            expect(fn () => $extension->getTtl($options))
                ->toThrow(MustBePositiveException::class);
        });

        test('onFunctionExecuted uses custom TTL from options', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);
            // Cache entry put (custom TTL 7200s)
            $cache->shouldReceive('put')->once()->with(
                Mockery::type('string'),
                Mockery::type('array'),
                7_200, // 2 hours in seconds
            );

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(true);
            $lock->shouldReceive('release')->once();

            Cache::shouldReceive('lock')
                ->once()
                ->with(Mockery::type('string'), 30)
                ->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0007',
                call: new CallData(function: 'test', arguments: ['a' => 1]),
            );
            $response = ResponseData::success(['ok' => true], '01JFEX0007');
            $extensionData = ExtensionData::request(
                ExtensionUrn::Idempotency->value,
                ['key' => 'custom-ttl', 'ttl' => ['value' => 2, 'unit' => 'hour']],
            );

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - verified by mock expectations
            expect(true)->toBeTrue();
        });
    });

    describe('Sad Paths', function (): void {
        test('onExecutingFunction returns conflict for duplicate request with different arguments', function (): void {
            // Arrange
            $cachedData = [
                'response' => ResponseData::success(['id' => 999], '01JFEX0008')->toArray(),
                'original_request_id' => '01JFEX0008',
                'arguments_hash' => 'sha256:'.hash('sha256', json_encode(['name' => 'Original'])),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => now()->addHour()->toIso8601String(),
            ];

            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn($cachedData);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0009',
                call: new CallData(function: 'createUser', arguments: ['name' => 'Test']),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'duplicate-key']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->isFailed())->toBeTrue()
                ->and($event->isPropagationStopped())->toBeTrue();

            $error = $result->getFirstError();
            expect($error->code)->toBe(ErrorCode::IdempotencyConflict->value);
        });

        test('onExecutingFunction returns conflict for same key with different arguments', function (): void {
            // Arrange
            $cachedData = [
                'response' => ResponseData::success(['id' => 1], '01JFEX0010')->toArray(),
                'original_request_id' => '01JFEX0010',
                'arguments_hash' => 'sha256:'.hash('sha256', json_encode(['name' => 'Original'])),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => now()->addHour()->toIso8601String(),
            ];

            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn($cachedData);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0011',
                call: new CallData(function: 'createUser', arguments: ['name' => 'Different']),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'conflict-key']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->isFailed())->toBeTrue()
                ->and($event->isPropagationStopped())->toBeTrue();

            $error = $result->getFirstError();
            expect($error->code)->toBe(ErrorCode::IdempotencyConflict->value)
                ->and($error->message)->toContain('different arguments');

            $ext = $result->extensions[0];
            expect($ext->data)->toHaveKey('status', IdempotencyExtension::STATUS_CONFLICT)
                ->and($ext->data)->toHaveKey('key', 'conflict-key');
        });

        test('onExecutingFunction detects conflict when arguments differ', function (): void {
            // Arrange
            $cachedData = [
                'response' => ResponseData::success(['ok' => true], '01JFEX0012')->toArray(),
                'original_request_id' => '01JFEX0012',
                'arguments_hash' => 'sha256:'.hash('sha256', json_encode(['x' => 1])),
                'cached_at' => now()->toIso8601String(),
                'expires_at' => now()->addHour()->toIso8601String(),
            ];

            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn($cachedData);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0013',
                call: new CallData(function: 'noArgs', arguments: null),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'null-args']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            expect($result)->toBeInstanceOf(ResponseData::class)
                ->and($result->isFailed())->toBeTrue()
                ->and($event->isPropagationStopped())->toBeTrue();

            $error = $result->getFirstError();
            expect($error->code)->toBe(ErrorCode::IdempotencyConflict->value);
        });

        test('onFunctionExecuted removes processing lock', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->once(); // cache entry

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(true);
            $lock->shouldReceive('release')->once(); // Verify lock is released

            Cache::shouldReceive('lock')->once()->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0014',
                call: new CallData(function: 'test', arguments: ['x' => 1]),
            );
            $response = ResponseData::success(['done' => true], '01JFEX0014');
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'lock-removal']);

            // Set up context first
            $executingEvent = new ExecutingFunction($request, $extensionData);
            $extension->onExecutingFunction($executingEvent);

            // Act
            $executedEvent = new FunctionExecuted($request, $extensionData, $response);
            $extension->onFunctionExecuted($executedEvent);

            // Assert - verified by mock expectations
            expect(true)->toBeTrue();
        });

        test('onExecutingFunction error response includes retry_after hint', function (): void {
            // Arrange
            $cache = mock(CacheRepository::class);
            $cache->shouldReceive('get')->andReturn(null);

            $lock = mock(Lock::class);
            $lock->shouldReceive('get')->andReturn(false); // Lock acquisition fails

            Cache::shouldReceive('lock')->once()->andReturn($lock);

            $extension = new IdempotencyExtension($cache);
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: '01JFEX0015',
                call: new CallData(function: 'test'),
            );
            $extensionData = ExtensionData::request(ExtensionUrn::Idempotency->value, ['key' => 'retry-test']);
            $event = new ExecutingFunction($request, $extensionData);

            // Act
            $extension->onExecutingFunction($event);

            // Assert
            $result = $event->getResponse();
            $error = $result->getFirstError();
            expect($error->details)->toHaveKey('retry_after')
                ->and($error->details['retry_after'])->toBe(['value' => 1, 'unit' => 'second']);
        });
    });
});

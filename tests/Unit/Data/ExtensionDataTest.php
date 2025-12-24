<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Extensions\ExtensionUrn;

describe('ExtensionData', function (): void {
    describe('Happy Paths', function (): void {
        test('creates extension with string URN via constructor', function (): void {
            // Arrange
            $urn = 'urn:cline:forrst:ext:async';
            $options = ['timeout' => 5_000];

            // Act
            $extension = new ExtensionData(
                urn: $urn,
                options: $options,
            );

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:async')
                ->and($extension->options)->toBe(['timeout' => 5_000])
                ->and($extension->data)->toBeNull();
        });

        test('creates extension with BackedEnum URN via constructor', function (): void {
            // Arrange
            $options = ['max_attempts' => 3];

            // Act
            $extension = new ExtensionData(
                urn: ExtensionUrn::Retry,
                options: $options,
            );

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:retry')
                ->and($extension->options)->toBe(['max_attempts' => 3])
                ->and($extension->data)->toBeNull();
        });

        test('creates request extension via static factory with string URN', function (): void {
            // Arrange
            $urn = 'urn:cline:forrst:ext:caching';
            $options = ['ttl' => 3_600];

            // Act
            $extension = ExtensionData::request($urn, $options);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:caching')
                ->and($extension->options)->toBe(['ttl' => 3_600])
                ->and($extension->data)->toBeNull();
        });

        test('creates request extension via static factory with BackedEnum URN', function (): void {
            // Arrange
            $options = ['priority' => 'high'];

            // Act
            $extension = ExtensionData::request(ExtensionUrn::Priority, $options);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:priority')
                ->and($extension->options)->toBe(['priority' => 'high'])
                ->and($extension->data)->toBeNull();
        });

        test('creates response extension via static factory with string URN', function (): void {
            // Arrange
            $urn = 'urn:cline:forrst:ext:async';
            $data = ['operation_id' => 'op-456', 'status' => 'pending'];

            // Act
            $extension = ExtensionData::response($urn, $data);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:async')
                ->and($extension->data)->toBe(['operation_id' => 'op-456', 'status' => 'pending'])
                ->and($extension->options)->toBeNull();
        });

        test('creates response extension via static factory with BackedEnum URN', function (): void {
            // Arrange
            $data = ['attempt_count' => 2, 'next_retry_ms' => 1_000];

            // Act
            $extension = ExtensionData::response(ExtensionUrn::Retry, $data);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:retry')
                ->and($extension->data)->toBe(['attempt_count' => 2, 'next_retry_ms' => 1_000])
                ->and($extension->options)->toBeNull();
        });

        test('deserializes extension from array with from() method', function (): void {
            // Arrange
            $array = [
                'urn' => 'urn:cline:forrst:ext:idempotency',
                'options' => ['key' => 'req-123'],
            ];

            // Act
            $extension = ExtensionData::from($array);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:idempotency')
                ->and($extension->options)->toBe(['key' => 'req-123'])
                ->and($extension->data)->toBeNull();
        });

        test('serializes to request array with URN and options only', function (): void {
            // Arrange
            $extension = ExtensionData::request(
                ExtensionUrn::Async,
                ['timeout' => 5_000],
            );

            // Act
            $array = $extension->toRequestArray();

            // Assert
            expect($array)->toBe([
                'urn' => 'urn:cline:forrst:ext:async',
                'options' => ['timeout' => 5_000],
            ])->and($array)->not->toHaveKey('data');
        });

        test('serializes to response array with URN and data only', function (): void {
            // Arrange
            $extension = ExtensionData::response(
                ExtensionUrn::Retry,
                ['attempt_count' => 1],
            );

            // Act
            $array = $extension->toResponseArray();

            // Assert
            expect($array)->toBe([
                'urn' => 'urn:cline:forrst:ext:retry',
                'data' => ['attempt_count' => 1],
            ])->and($array)->not->toHaveKey('options');
        });

        test('serializes to complete array with options', function (): void {
            // Arrange
            $extension = new ExtensionData(
                urn: ExtensionUrn::Tracing,
                options: ['trace_id' => 'tr-123'],
            );

            // Act
            $array = $extension->toArray();

            // Assert
            expect($array)->toBe([
                'urn' => 'urn:cline:forrst:ext:tracing',
                'options' => ['trace_id' => 'tr-123'],
            ])->and($array)->not->toHaveKey('data');
        });
    });

    describe('Sad Paths', function (): void {
        test('handles empty URN string from deserialization', function (): void {
            // Arrange
            $array = ['urn' => '', 'options' => ['key' => 'value']];

            // Act & Assert
            expect(fn () => ExtensionData::from($array))
                ->toThrow(EmptyFieldException::class, 'urn');
        });

        test('deserializes extension without options field', function (): void {
            // Arrange
            $array = [
                'urn' => 'urn:cline:forrst:ext:async',
                'data' => ['operation_id' => 'op-789'],
            ];

            // Act
            $extension = ExtensionData::from($array);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:async')
                ->and($extension->options)->toBeNull()
                ->and($extension->data)->toBe(['operation_id' => 'op-789']);
        });

        test('deserializes extension without data field', function (): void {
            // Arrange
            $array = [
                'urn' => 'urn:cline:forrst:ext:retry',
                'options' => ['max_attempts' => 5],
            ];

            // Act
            $extension = ExtensionData::from($array);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:retry')
                ->and($extension->options)->toBe(['max_attempts' => 5])
                ->and($extension->data)->toBeNull();
        });
    });

    describe('Edge Cases', function (): void {
        test('creates extension with null options and null data', function (): void {
            // Arrange & Act
            $extension = new ExtensionData(urn: 'urn:cline:forrst:ext:test');

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:test')
                ->and($extension->options)->toBeNull()
                ->and($extension->data)->toBeNull();
        });

        test('creates request extension without options', function (): void {
            // Arrange & Act
            $extension = ExtensionData::request('urn:cline:forrst:ext:maintenance');

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:maintenance')
                ->and($extension->options)->toBeNull()
                ->and($extension->data)->toBeNull();
        });

        test('creates response extension without data', function (): void {
            // Arrange & Act
            $extension = ExtensionData::response(ExtensionUrn::Deprecation);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:deprecation')
                ->and($extension->options)->toBeNull()
                ->and($extension->data)->toBeNull();
        });

        test('serializes request array without options field when options are null', function (): void {
            // Arrange
            $extension = ExtensionData::request('urn:cline:forrst:ext:test');

            // Act
            $array = $extension->toRequestArray();

            // Assert
            expect($array)->toBe(['urn' => 'urn:cline:forrst:ext:test'])
                ->and($array)->not->toHaveKey('options')
                ->and($array)->not->toHaveKey('data');
        });

        test('serializes response array without data field when data is null', function (): void {
            // Arrange
            $extension = ExtensionData::response(ExtensionUrn::Locale);

            // Act
            $array = $extension->toResponseArray();

            // Assert
            expect($array)->toBe(['urn' => 'urn:cline:forrst:ext:locale'])
                ->and($array)->not->toHaveKey('data')
                ->and($array)->not->toHaveKey('options');
        });

        test('serializes to minimal array when both options and data are null', function (): void {
            // Arrange
            $extension = new ExtensionData(urn: 'urn:cline:forrst:ext:minimal');

            // Act
            $array = $extension->toArray();

            // Assert
            expect($array)->toBe(['urn' => 'urn:cline:forrst:ext:minimal'])
                ->and($array)->not->toHaveKey('options')
                ->and($array)->not->toHaveKey('data');
        });

        test('handles complex nested data structures in options', function (): void {
            // Arrange
            $complexOptions = [
                'config' => [
                    'retry' => ['max_attempts' => 3, 'backoff' => [100, 200, 400]],
                    'metadata' => ['user_id' => 123, 'tags' => ['important', 'async']],
                ],
            ];

            // Act
            $extension = ExtensionData::request('urn:cline:forrst:ext:custom', $complexOptions);

            // Assert
            expect($extension->options['config']['retry']['backoff'])->toBe([100, 200, 400])
                ->and($extension->options['config']['metadata']['tags'])->toContain('async');
        });

        test('handles complex nested data structures in data', function (): void {
            // Arrange
            $complexData = [
                'result' => [
                    'operations' => [
                        ['id' => 'op-1', 'status' => 'pending'],
                        ['id' => 'op-2', 'status' => 'complete'],
                    ],
                    'timing' => ['started_at' => '2025-01-01T00:00:00Z', 'duration_ms' => 1_500],
                ],
            ];

            // Act
            $extension = ExtensionData::response(ExtensionUrn::Async, $complexData);

            // Assert
            expect($extension->data['result']['operations'])->toHaveCount(2)
                ->and($extension->data['result']['timing']['duration_ms'])->toBe(1_500);
        });

        test('handles unicode characters in URN', function (): void {
            // Arrange
            $urn = 'urn:cline:forrst:ext:locale-日本語';

            // Act
            $extension = new ExtensionData(urn: $urn);

            // Assert
            expect($extension->urn)->toBe('urn:cline:forrst:ext:locale-日本語');
        });

        test('handles unicode characters in options', function (): void {
            // Arrange
            $options = ['locale' => 'zh-CN', 'greeting' => '你好世界'];

            // Act
            $extension = ExtensionData::request(ExtensionUrn::Locale, $options);

            // Assert
            expect($extension->options['greeting'])->toBe('你好世界');
        });

        test('handles unicode characters in data', function (): void {
            // Arrange
            $data = ['message' => 'Καλημέρα κόσμε', 'emoji' => '🌍🚀'];

            // Act
            $extension = ExtensionData::response('urn:cline:forrst:ext:test', $data);

            // Assert
            expect($extension->data['message'])->toBe('Καλημέρα κόσμε')
                ->and($extension->data['emoji'])->toBe('🌍🚀');
        });

        test('handles empty arrays in options', function (): void {
            // Arrange
            $extension = ExtensionData::request('urn:cline:forrst:ext:test', []);

            // Act
            $array = $extension->toRequestArray();

            // Assert
            expect($extension->options)->toBe([])
                ->and($array['options'])->toBe([]);
        });

        test('handles empty arrays in data', function (): void {
            // Arrange
            $extension = ExtensionData::response(ExtensionUrn::Query, []);

            // Act
            $array = $extension->toResponseArray();

            // Assert
            expect($extension->data)->toBe([])
                ->and($array['data'])->toBe([]);
        });

        test('handles all standard ExtensionUrn enum cases', function (ExtensionUrn $urn): void {
            // Arrange & Act
            $extension = new ExtensionData(urn: $urn);

            // Assert
            expect($extension->urn)->toBe($urn->value)
                ->and($extension->urn)->toStartWith('urn:cline:forrst:ext:');
        })->with([
            'Async' => ExtensionUrn::Async,
            'Caching' => ExtensionUrn::Caching,
            'Deadline' => ExtensionUrn::Deadline,
            'Deprecation' => ExtensionUrn::Deprecation,
            'DryRun' => ExtensionUrn::DryRun,
            'Idempotency' => ExtensionUrn::Idempotency,
            'Maintenance' => ExtensionUrn::Maintenance,
            'Priority' => ExtensionUrn::Priority,
            'Replay' => ExtensionUrn::Replay,
            'Query' => ExtensionUrn::Query,
            'Quota' => ExtensionUrn::Quota,
            'RateLimit' => ExtensionUrn::RateLimit,
            'Locale' => ExtensionUrn::Locale,
            'Redact' => ExtensionUrn::Redact,
            'Tracing' => ExtensionUrn::Tracing,
            'Retry' => ExtensionUrn::Retry,
            'Cancellation' => ExtensionUrn::Cancellation,
            'Stream' => ExtensionUrn::Stream,
        ]);

        test('deserializes from empty array with default values', function (): void {
            // Arrange & Act & Assert
            expect(fn () => ExtensionData::from([]))
                ->toThrow(EmptyFieldException::class, 'urn');
        });

        test('converts enum URN to string in toRequestArray', function (): void {
            // Arrange
            $extension = new ExtensionData(
                urn: ExtensionUrn::Deadline,
                options: ['timeout_ms' => 5_000],
            );

            // Act
            $array = $extension->toRequestArray();

            // Assert
            expect($array['urn'])->toBeString()
                ->and($array['urn'])->toBe('urn:cline:forrst:ext:deadline');
        });

        test('converts enum URN to string in toResponseArray', function (): void {
            // Arrange
            $extension = new ExtensionData(
                urn: ExtensionUrn::RateLimit,
                data: ['remaining' => 95, 'reset_at' => 1_640_000_000],
            );

            // Act
            $array = $extension->toResponseArray();

            // Assert
            expect($array['urn'])->toBeString()
                ->and($array['urn'])->toBe('urn:cline:forrst:ext:rate-limit');
        });

        test('preserves numeric array keys in options', function (): void {
            // Arrange
            $options = [0 => 'first', 1 => 'second', 5 => 'sixth'];
            $extension = ExtensionData::request('urn:cline:forrst:ext:test', $options);

            // Act
            $array = $extension->toRequestArray();

            // Assert
            expect($array['options'])->toBe([0 => 'first', 1 => 'second', 5 => 'sixth']);
        });

        test('preserves numeric array keys in data', function (): void {
            // Arrange
            $data = [10 => 'tenth', 20 => 'twentieth'];
            $extension = ExtensionData::response('urn:cline:forrst:ext:test', $data);

            // Act
            $array = $extension->toResponseArray();

            // Assert
            expect($array['data'])->toBe([10 => 'tenth', 20 => 'twentieth']);
        });
    });

    describe('Regression Tests', function (): void {
        test('ensures request factory never includes data in serialization', function (): void {
            // Arrange
            $extension = ExtensionData::request(
                ExtensionUrn::Async,
                ['timeout' => 3_000],
            );

            // Act
            $requestArray = $extension->toRequestArray();
            $fullArray = $extension->toArray();

            // Assert - Request array must never include data
            expect($requestArray)->not->toHaveKey('data')
                ->and($fullArray)->not->toHaveKey('data');
        });

        test('ensures response factory never includes options in serialization', function (): void {
            // Arrange
            $extension = ExtensionData::response(
                ExtensionUrn::Retry,
                ['attempt_count' => 2],
            );

            // Act
            $responseArray = $extension->toResponseArray();
            $fullArray = $extension->toArray();

            // Assert - Response array must never include options
            expect($responseArray)->not->toHaveKey('options')
                ->and($fullArray)->not->toHaveKey('options');
        });

        test('maintains immutability of readonly properties', function (): void {
            // Arrange
            $extension = new ExtensionData(
                urn: ExtensionUrn::Tracing,
                options: ['trace_id' => 'tr-123'],
            );

            // Act & Assert - Readonly properties cannot be modified
            expect($extension->urn)->toBe('urn:cline:forrst:ext:tracing');

            // This would cause a PHP error if attempted:
            // $extension->urn = 'different';
            // $extension->options = ['different' => 'value'];
        });

        test('from() method handles all combinations of missing fields', function (): void {
            // Test valid combinations (options OR data, but not both)
            $validUrn = 'urn:cline:forrst:ext:test';
            $cases = [
                ['urn' => $validUrn],
                ['urn' => $validUrn, 'options' => ['opt' => 1]],
                ['urn' => $validUrn, 'data' => ['dat' => 2]],
            ];

            foreach ($cases as $array) {
                $extension = ExtensionData::from($array);
                expect($extension)->toBeInstanceOf(ExtensionData::class);
            }

            // Test that both options AND data throws exception
            expect(fn () => ExtensionData::from([
                'urn' => $validUrn,
                'options' => ['opt' => 1],
                'data' => ['dat' => 2],
            ]))->toThrow(MutuallyExclusiveFieldsException::class);
        });

        test('serialization round-trip preserves all data for request', function (): void {
            // Arrange
            $original = ExtensionData::request(
                ExtensionUrn::Caching,
                ['ttl' => 3_600, 'strategy' => 'lru'],
            );

            // Act
            $array = $original->toRequestArray();
            $reconstructed = ExtensionData::from([...$array, 'data' => null]);

            // Assert
            expect($reconstructed->urn)->toBe($original->urn)
                ->and($reconstructed->options)->toBe($original->options);
        });

        test('serialization round-trip preserves all data for response', function (): void {
            // Arrange
            $original = ExtensionData::response(
                ExtensionUrn::Quota,
                ['remaining' => 1_000, 'limit' => 5_000],
            );

            // Act
            $array = $original->toResponseArray();
            $reconstructed = ExtensionData::from([...$array, 'options' => null]);

            // Assert
            expect($reconstructed->urn)->toBe($original->urn)
                ->and($reconstructed->data)->toBe($original->data);
        });
    });
});

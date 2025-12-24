<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Carbon\CarbonImmutable;
use Cline\Forrst\Data\CallData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Extensions\CachingExtension;
use Cline\Forrst\Extensions\ExtensionUrn;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Mockery as m;

describe('CachingExtension', function (): void {
    describe('Happy Paths', function (): void {
        test('returns correct URN constant', function (): void {
            // Arrange
            $extension = new CachingExtension();

            // Act
            $urn = $extension->getUrn();

            // Assert
            expect($urn)->toBe(ExtensionUrn::Caching->value);
        });

        test('getIfNoneMatch returns ETag from options', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $options = ['if_none_match' => '"abc123"'];

            // Act
            $result = $extension->getIfNoneMatch($options);

            // Assert
            expect($result)->toBe('"abc123"');
        });

        test('getIfModifiedSince returns Carbon timestamp from options', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $timestamp = '2024-01-15T10:30:00Z';
            $options = ['if_modified_since' => $timestamp];

            // Act
            $result = $extension->getIfModifiedSince($options);

            // Assert
            expect($result)->toBeInstanceOf(CarbonImmutable::class)
                ->and($result->toIso8601String())->toBe('2024-01-15T10:30:00+00:00');
        });

        test('isValid returns true when ETags match', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientEtag = '"abc123"';
            $currentEtag = '"abc123"';

            // Act
            $result = $extension->isValid($clientEtag, null, $currentEtag, null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('isValid returns true when current is not modified since client timestamp', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientModified = CarbonImmutable::parse('2024-01-15 12:00:00');
            $currentModified = CarbonImmutable::parse('2024-01-15 10:00:00');

            // Act
            $result = $extension->isValid(null, $clientModified, '"etag"', $currentModified);

            // Assert
            expect($result)->toBeTrue();
        });

        test('generateEtag creates consistent hash', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $value = ['data' => 'test'];

            // Act
            $etag1 = $extension->generateEtag($value);
            $etag2 = $extension->generateEtag($value);

            // Assert
            expect($etag1)->toBe($etag2)
                ->and($etag1)->toStartWith('"')
                ->and($etag1)->toEndWith('"');
        });

        test('buildCacheHitResponse returns not modified response', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(function: 'urn:cline:forrst:fn:test:function'),
            );
            $etag = '"abc123"';

            // Act
            $response = $extension->buildCacheHitResponse($request, $etag);

            // Assert
            expect($response->id)->toBe('req-123')
                ->and($response->result)->toBeNull()
                ->and($response->extensions)->toHaveCount(1)
                ->and($response->extensions[0]->data['etag'])->toBe('"abc123"')
                ->and($response->extensions[0]->data['cache_status'])->toBe(CachingExtension::STATUS_HIT);
        });

        test('buildCacheMetadata creates metadata with etag and status', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $etag = '"xyz789"';

            // Act
            $metadata = $extension->buildCacheMetadata($etag, CachingExtension::STATUS_MISS);

            // Assert
            expect($metadata)->toHaveKey('etag')
                ->and($metadata)->toHaveKey('cache_status')
                ->and($metadata['etag'])->toBe('"xyz789"')
                ->and($metadata['cache_status'])->toBe(CachingExtension::STATUS_MISS);
        });

        test('buildCacheMetadata includes max_age when provided', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $etag = '"abc"';

            // Act
            $metadata = $extension->buildCacheMetadata($etag, CachingExtension::STATUS_MISS, 600);

            // Assert
            expect($metadata)->toHaveKey('max_age')
                ->and($metadata['max_age']['value'])->toBe(600)
                ->and($metadata['max_age']['unit'])->toBe('second');
        });

        test('buildCacheMetadata includes last_modified when provided', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $etag = '"abc"';
            $lastModified = CarbonImmutable::parse('2024-01-15 10:30:00');

            // Act
            $metadata = $extension->buildCacheMetadata($etag, CachingExtension::STATUS_MISS, null, $lastModified);

            // Assert
            expect($metadata)->toHaveKey('last_modified')
                ->and($metadata['last_modified'])->toContain('2024-01-15');
        });

        test('enrichResponse adds cache extension to response', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $response = ResponseData::success(['data' => 'test'], 'req-123');
            $etag = '"cache123"';

            // Act
            $enriched = $extension->enrichResponse($response, $etag);

            // Assert
            expect($enriched->extensions)->toHaveCount(1)
                ->and($enriched->extensions[0]->urn)->toBe(ExtensionUrn::Caching->value)
                ->and($enriched->extensions[0]->data['etag'])->toBe('"cache123"')
                ->and($enriched->result)->toBe(['data' => 'test']);
        });

        test('enrichResponse preserves existing extensions', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $existingExtension = ExtensionData::response('urn:cline:forrst:ext:custom', ['key' => 'value']);
            $response = ResponseData::success(
                ['data' => 'test'],
                'req-123',
                extensions: [$existingExtension],
            );
            $etag = '"cache123"';

            // Act
            $enriched = $extension->enrichResponse($response, $etag);

            // Assert
            expect($enriched->extensions)->toHaveCount(2)
                ->and($enriched->extensions[0]->urn)->toBe('urn:cline:forrst:ext:custom')
                ->and($enriched->extensions[1]->urn)->toBe(ExtensionUrn::Caching->value);
        });

        test('getCached returns null when cache is not configured', function (): void {
            // Arrange
            $extension = new CachingExtension();

            // Act
            $result = $extension->getCached('cache_key');

            // Assert
            expect($result)->toBeNull();
        });

        test('getCached returns null when cached value is not array', function (): void {
            // Arrange
            $cache = m::mock(CacheRepository::class);
            $cache->shouldReceive('get')
                ->once()
                ->with('cache_key')
                ->andReturn('not_an_array');

            $extension = new CachingExtension(cache: $cache);

            // Act
            $result = $extension->getCached('cache_key');

            // Assert
            expect($result)->toBeNull();
        });

        test('setCached stores response in cache', function (): void {
            // Arrange
            $cache = m::mock(CacheRepository::class);
            $cache->shouldReceive('put')
                ->once()
                ->with('cache_key', m::type('array'), 300);

            $extension = new CachingExtension(cache: $cache, defaultTtl: 300);
            $response = ResponseData::success(['data' => 'test'], 'req-1');

            // Act
            $extension->setCached('cache_key', $response);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('setCached uses custom TTL when provided', function (): void {
            // Arrange
            $cache = m::mock(CacheRepository::class);
            $cache->shouldReceive('put')
                ->once()
                ->with('cache_key', m::type('array'), 600);

            $extension = new CachingExtension(cache: $cache, defaultTtl: 300);
            $response = ResponseData::success(['data' => 'test'], 'req-1');

            // Act
            $extension->setCached('cache_key', $response, 600);

            // Assert - Verified via mock expectations
            expect(true)->toBeTrue();
        });

        test('buildCacheKey creates consistent key from request', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(
                    function: 'urn:cline:forrst:fn:test:function',
                    version: '1',
                    arguments: ['key' => 'value'],
                ),
            );

            // Act
            $cacheKey = $extension->buildCacheKey($request);

            // Assert
            expect($cacheKey)->toContain('forrst_cache')
                ->and($cacheKey)->toContain('urn:cline:forrst:fn:test:function')
                ->and($cacheKey)->toContain('1');
        });

        test('buildCacheKey creates different keys for different arguments', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $request1 = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value1']);
            $request2 = RequestObjectData::asRequest('urn:cline:forrst:fn:test:function', ['arg' => 'value2']);

            // Act
            $key1 = $extension->buildCacheKey($request1);
            $key2 = $extension->buildCacheKey($request2);

            // Assert
            expect($key1)->not->toBe($key2);
        });
    });

    describe('Edge Cases', function (): void {
        test('getIfNoneMatch returns null when option not set', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $options = [];

            // Act
            $result = $extension->getIfNoneMatch($options);

            // Assert
            expect($result)->toBeNull();
        });

        test('getIfNoneMatch handles null options', function (): void {
            // Arrange
            $extension = new CachingExtension();

            // Act
            $result = $extension->getIfNoneMatch(null);

            // Assert
            expect($result)->toBeNull();
        });

        test('getIfModifiedSince returns null when option not set', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $options = [];

            // Act
            $result = $extension->getIfModifiedSince($options);

            // Assert
            expect($result)->toBeNull();
        });

        test('getIfModifiedSince handles null options', function (): void {
            // Arrange
            $extension = new CachingExtension();

            // Act
            $result = $extension->getIfModifiedSince(null);

            // Assert
            expect($result)->toBeNull();
        });

        test('isValid returns false when ETags do not match', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientEtag = '"abc123"';
            $currentEtag = '"xyz789"';

            // Act
            $result = $extension->isValid($clientEtag, null, $currentEtag, null);

            // Assert
            expect($result)->toBeFalse();
        });

        test('isValid returns false when current is newer than client timestamp', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientModified = CarbonImmutable::parse('2024-01-15 10:00:00');
            $currentModified = CarbonImmutable::parse('2024-01-15 12:00:00');

            // Act
            $result = $extension->isValid(null, $clientModified, '"etag"', $currentModified);

            // Assert
            expect($result)->toBeFalse();
        });

        test('isValid normalizes ETags for comparison', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientEtag = 'abc123'; // Without quotes
            $currentEtag = '"abc123"'; // With quotes

            // Act
            $result = $extension->isValid($clientEtag, null, $currentEtag, null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('isValid handles weak validator prefix', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientEtag = 'W/"abc123"'; // Weak validator
            $currentEtag = '"abc123"';

            // Act
            $result = $extension->isValid($clientEtag, null, $currentEtag, null);

            // Assert
            expect($result)->toBeTrue();
        });

        test('isValid returns false when neither ETag nor timestamp provided', function (): void {
            // Arrange
            $extension = new CachingExtension();

            // Act
            $result = $extension->isValid(null, null, '"etag"', null);

            // Assert
            expect($result)->toBeFalse();
        });

        test('isValid prioritizes ETag over timestamp', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientEtag = '"abc123"';
            $clientModified = CarbonImmutable::parse('2024-01-15 12:00:00');
            $currentEtag = '"abc123"';
            $currentModified = CarbonImmutable::parse('2024-01-15 10:00:00');

            // Act - ETag matches but timestamp would indicate stale
            $result = $extension->isValid($clientEtag, $clientModified, $currentEtag, $currentModified);

            // Assert
            expect($result)->toBeTrue();
        });

        test('generateEtag creates different ETags for different values', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $value1 = ['data' => 'test1'];
            $value2 = ['data' => 'test2'];

            // Act
            $etag1 = $extension->generateEtag($value1);
            $etag2 = $extension->generateEtag($value2);

            // Assert
            expect($etag1)->not->toBe($etag2);
        });

        test('buildCacheMetadata uses default TTL when maxAge is null', function (): void {
            // Arrange
            $extension = new CachingExtension(defaultTtl: 600);
            $etag = '"abc"';

            // Act
            $metadata = $extension->buildCacheMetadata($etag);

            // Assert
            expect($metadata)->toHaveKey('max_age')
                ->and($metadata['max_age']['value'])->toBe(600);
        });

        test('buildCacheMetadata omits max_age when TTL is 0', function (): void {
            // Arrange
            $extension = new CachingExtension(defaultTtl: 0);
            $etag = '"abc"';

            // Act
            $metadata = $extension->buildCacheMetadata($etag);

            // Assert
            expect($metadata)->not->toHaveKey('max_age');
        });

        test('getCached returns null when cache misses', function (): void {
            // Arrange
            $cache = m::mock(CacheRepository::class);
            $cache->shouldReceive('get')
                ->once()
                ->with('cache_key')
                ->andReturn(null);

            $extension = new CachingExtension(cache: $cache);

            // Act
            $result = $extension->getCached('cache_key');

            // Assert
            expect($result)->toBeNull();
        });

        test('setCached does nothing when cache is not configured', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $response = ResponseData::success(['data' => 'test'], 'req-1');

            // Act
            $extension->setCached('cache_key', $response);

            // Assert - No exception thrown
            expect(true)->toBeTrue();
        });

        test('buildCacheKey handles null version', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(
                    function: 'urn:cline:forrst:fn:test:function',
                    version: null,
                    arguments: ['key' => 'value'],
                ),
            );

            // Act
            $cacheKey = $extension->buildCacheKey($request);

            // Assert
            expect($cacheKey)->toContain('latest');
        });

        test('buildCacheKey handles null arguments', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $request = new RequestObjectData(
                protocol: ProtocolData::forrst(),
                id: 'req-123',
                call: new CallData(
                    function: 'urn:cline:forrst:fn:test:function',
                    arguments: null,
                ),
            );

            // Act
            $cacheKey = $extension->buildCacheKey($request);

            // Assert
            expect($cacheKey)->toBeString()
                ->and($cacheKey)->toContain('forrst_cache');
        });

        test('enrichResponse with custom cache status', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $response = ResponseData::success(['data' => 'test'], 'req-123');
            $etag = '"cache123"';

            // Act
            $enriched = $extension->enrichResponse($response, $etag, CachingExtension::STATUS_STALE);

            // Assert
            expect($enriched->extensions[0]->data['cache_status'])->toBe(CachingExtension::STATUS_STALE);
        });

        test('buildCacheMetadata includes all optional fields', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $etag = '"full"';
            $lastModified = CarbonImmutable::parse('2024-01-15 10:30:00');

            // Act
            $metadata = $extension->buildCacheMetadata(
                $etag,
                CachingExtension::STATUS_HIT,
                600,
                $lastModified,
            );

            // Assert
            expect($metadata)->toHaveKey('etag')
                ->and($metadata)->toHaveKey('cache_status')
                ->and($metadata)->toHaveKey('max_age')
                ->and($metadata)->toHaveKey('last_modified');
        });
    });

    describe('Sad Paths', function (): void {
        test('isValid returns false when only client timestamp provided without current', function (): void {
            // Arrange
            $extension = new CachingExtension();
            $clientModified = CarbonImmutable::parse('2024-01-15 10:00:00');

            // Act
            $result = $extension->isValid(null, $clientModified, '"etag"', null);

            // Assert
            expect($result)->toBeFalse();
        });
    });
});

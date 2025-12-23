<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Carbon\CarbonImmutable;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Override;

use function assert;
use function hash;
use function implode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function mb_substr;
use function mb_trim;
use function preg_replace;
use function sprintf;

/**
 * Caching extension handler.
 *
 * Implements HTTP-style caching semantics for Forrst function calls using ETags,
 * Last-Modified timestamps, and conditional requests. Reduces bandwidth and
 * computation by allowing clients to reuse previously retrieved results when
 * the underlying data has not changed.
 *
 * Unlike lifecycle extensions, this is primarily a helper extension. Function
 * implementations should explicitly call the provided methods to check cache
 * validity and enrich responses with cache metadata.
 *
 * Request options:
 * - if_none_match: string - ETag from previous response for conditional request
 * - if_modified_since: string - ISO 8601 timestamp for time-based validation
 *
 * Response data:
 * - etag: unique identifier for response version
 * - max_age: duration the response can be cached
 * - last_modified: ISO 8601 timestamp of last modification
 * - cache_status: hit, miss, stale, or bypass
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/caching
 */
final class CachingExtension extends AbstractExtension
{
    /**
     * Cache status indicating client's cached copy is still valid.
     */
    public const string STATUS_HIT = 'hit';

    /**
     * Cache status indicating fresh response generated.
     */
    public const string STATUS_MISS = 'miss';

    /**
     * Cache status indicating cached copy exists but is outdated.
     */
    public const string STATUS_STALE = 'stale';

    /**
     * Cache status indicating caching was intentionally bypassed.
     */
    public const string STATUS_BYPASS = 'bypass';

    /**
     * Default cache TTL in seconds (5 minutes).
     */
    private const int DEFAULT_TTL_SECONDS = 300;

    /**
     * Create a new caching extension instance.
     *
     * @param null|CacheRepository $cache      Optional Laravel cache repository for server-side
     *                                         caching. If null, only client-side caching metadata
     *                                         is provided (ETags, Last-Modified headers).
     * @param int                  $defaultTtl Default time-to-live in seconds for cached responses.
     *                                         Functions can override this per-response.
     */
    public function __construct(
        private readonly ?CacheRepository $cache = null,
        private readonly int $defaultTtl = self::DEFAULT_TTL_SECONDS,
    ) {}

    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::Caching->value;
    }

    #[Override()]
    public function isErrorFatal(): bool
    {
        return false;
    }

    /**
     * Extract client's ETag for conditional request validation.
     *
     * Used to check if the client's cached version matches the current resource
     * version, enabling "304 Not Modified" style responses.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return null|string ETag value to validate against, or null if not provided
     */
    public function getIfNoneMatch(?array $options): ?string
    {
        $value = $options['if_none_match'] ?? null;
        assert($value === null || is_string($value));

        return $value;
    }

    /**
     * Extract client's last-known modification time for validation.
     *
     * Used for time-based cache validation when ETags are not available.
     * If the resource hasn't been modified since this timestamp, a cache
     * hit response can be returned.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return null|CarbonImmutable Timestamp to validate against, or null if not provided
     */
    public function getIfModifiedSince(?array $options): ?CarbonImmutable
    {
        $value = $options['if_modified_since'] ?? null;

        if ($value === null) {
            return null;
        }

        assert(is_string($value) || $value instanceof DateTimeInterface || is_int($value) || is_float($value));

        return CarbonImmutable::parse($value);
    }

    /**
     * Validate if client's cached version matches current resource state.
     *
     * Implements cache validation logic with ETag taking precedence over
     * timestamp comparison. Returns true if the client's cached version
     * is still current and can be reused.
     *
     * @param null|string          $clientEtag      Client's cached ETag value
     * @param null|CarbonImmutable $clientModified  Client's cached modification timestamp
     * @param string               $currentEtag     Current resource ETag
     * @param null|CarbonImmutable $currentModified Current resource modification timestamp
     *
     * @return bool True if client's cache is valid and can be reused
     */
    public function isValid(
        ?string $clientEtag,
        ?CarbonImmutable $clientModified,
        string $currentEtag,
        ?CarbonImmutable $currentModified,
    ): bool {
        // ETag check takes precedence
        if ($clientEtag !== null) {
            return $this->normalizeEtag($clientEtag) === $this->normalizeEtag($currentEtag);
        }

        // Fall back to timestamp check
        if ($clientModified instanceof CarbonImmutable && $currentModified instanceof CarbonImmutable) {
            return $currentModified->lessThanOrEqualTo($clientModified);
        }

        return false;
    }

    /**
     * Generate ETag from response value.
     *
     * Creates a hash-based identifier for cache validation using SHA-256.
     * Uses first 16 characters (64 bits) for strong collision resistance
     * while keeping ETags reasonably compact. ETags are quoted per HTTP conventions.
     *
     * @param mixed $value Response value to hash (will be JSON-encoded)
     *
     * @return string Quoted ETag string suitable for cache validation
     *
     * @throws \JsonException If value cannot be JSON-encoded
     */
    public function generateEtag(mixed $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $hash = mb_substr(hash('sha256', $json), 0, 16); // 16 chars = 64 bits

        return sprintf('"%s"', $hash);
    }

    /**
     * Build cache hit response indicating client's version is current.
     *
     * Creates a response with null result but cache_status=hit to signal the
     * client should use their cached copy. Analogous to HTTP 304 Not Modified.
     *
     * @param RequestObjectData $request Original request being validated
     * @param string            $etag    Current ETag matching client's cached version
     *
     * @return ResponseData Response indicating cache hit with no body
     */
    public function buildCacheHitResponse(RequestObjectData $request, string $etag): ResponseData
    {
        return ResponseData::success(
            result: null,
            id: $request->id,
            extensions: [
                ExtensionData::response(ExtensionUrn::Caching->value, [
                    'etag' => $etag,
                    'cache_status' => self::STATUS_HIT,
                ]),
            ],
        );
    }

    /**
     * Build cache metadata structure for response enrichment.
     *
     * Creates the cache extension data structure with ETag, cache status,
     * optional max-age directive, and last-modified timestamp.
     *
     * @param string               $etag         ETag for this response version
     * @param string               $cacheStatus  Cache status (hit, miss, stale, bypass)
     * @param null|int             $maxAge       Optional max age in seconds (uses default if null)
     * @param null|CarbonImmutable $lastModified Optional last modification timestamp
     *
     * @return array<string, mixed> Cache metadata structure for extension data
     */
    public function buildCacheMetadata(
        string $etag,
        string $cacheStatus = self::STATUS_MISS,
        ?int $maxAge = null,
        ?CarbonImmutable $lastModified = null,
    ): array {
        $data = [
            'etag' => $etag,
            'cache_status' => $cacheStatus,
        ];

        if ($maxAge !== null || $this->defaultTtl > 0) {
            $data['max_age'] = [
                'value' => $maxAge ?? $this->defaultTtl,
                'unit' => 'second',
            ];
        }

        if ($lastModified instanceof CarbonImmutable) {
            $data['last_modified'] = $lastModified->toIso8601String();
        }

        return $data;
    }

    /**
     * Add cache metadata to response.
     *
     * Appends caching extension data to an existing response, enabling client-side
     * caching. Function handlers should call this for cacheable responses.
     *
     * @param ResponseData         $response     Original response to enrich
     * @param string               $etag         ETag for this response version
     * @param string               $cacheStatus  Cache status (hit, miss, stale, bypass)
     * @param null|int             $maxAge       Optional max age in seconds
     * @param null|CarbonImmutable $lastModified Optional last modification timestamp
     *
     * @return ResponseData New response instance with cache metadata added
     */
    public function enrichResponse(
        ResponseData $response,
        string $etag,
        string $cacheStatus = self::STATUS_MISS,
        ?int $maxAge = null,
        ?CarbonImmutable $lastModified = null,
    ): ResponseData {
        $metadata = $this->buildCacheMetadata($etag, $cacheStatus, $maxAge, $lastModified);

        $extensions = $response->extensions ?? [];
        $extensions[] = ExtensionData::response(ExtensionUrn::Caching->value, $metadata);

        return new ResponseData(
            protocol: $response->protocol,
            id: $response->id,
            result: $response->result,
            errors: $response->errors,
            extensions: $extensions,
            meta: $response->meta,
        );
    }

    /**
     * Retrieve cached response from server-side cache.
     *
     * Attempts to fetch a previously cached response from the Laravel cache
     * repository. Returns null if no cache store is configured or entry not found.
     *
     * @param string $cacheKey Unique cache key for this request
     *
     * @return null|ResponseData Cached response or null if not found
     */
    public function getCached(string $cacheKey): ?ResponseData
    {
        if (!$this->cache instanceof CacheRepository) {
            return null;
        }

        $cached = $this->cache->get($cacheKey);

        if (!is_array($cached)) {
            return null;
        }

        /** @var array<string, mixed> $cachedData */
        $cachedData = $cached;

        return ResponseData::from($cachedData);
    }

    /**
     * Store response in server-side cache.
     *
     * Persists a response to the Laravel cache repository for future retrieval.
     * No-op if no cache store is configured.
     *
     * @param string       $cacheKey Unique cache key for this request
     * @param ResponseData $response Response to cache
     * @param null|int     $ttl      Time-to-live in seconds (uses default if null)
     */
    public function setCached(string $cacheKey, ResponseData $response, ?int $ttl = null): void
    {
        if (!$this->cache instanceof CacheRepository) {
            return;
        }

        $this->cache->put($cacheKey, $response->toArray(), $ttl ?? $this->defaultTtl);
    }

    /**
     * Generate cache key from request data.
     *
     * Creates a deterministic cache key incorporating function name, version,
     * and argument hash. Ensures requests with identical inputs produce the
     * same cache key for hit detection.
     *
     * @param RequestObjectData $request Request to generate key for
     *
     * @return string Unique cache key for this request
     */
    public function buildCacheKey(RequestObjectData $request): string
    {
        $json = json_encode($request->call->arguments ?? []);
        assert($json !== false);

        $parts = [
            'forrst_cache',
            $request->call->function,
            $request->call->version ?? 'latest',
            md5($json),
        ];

        return implode(':', $parts);
    }

    /**
     * Normalize ETag for comparison.
     *
     * Strips weak validator prefix (W/) and surrounding quotes to enable
     * reliable ETag matching regardless of client formatting.
     *
     * @param string $etag ETag value to normalize
     *
     * @return string Normalized ETag without quotes or weak prefix
     */
    private function normalizeEtag(string $etag): string
    {
        // Remove weak validator prefix and quotes
        $normalized = preg_replace('/^W\//', '', $etag);
        assert(is_string($normalized));

        return mb_trim($normalized, '"');
    }
}

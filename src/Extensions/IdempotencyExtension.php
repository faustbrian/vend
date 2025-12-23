<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Override;

use function assert;
use function hash;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_encode;
use function now;

/**
 * Idempotency extension handler.
 *
 * Ensures that retrying a request does not cause duplicate side effects by caching
 * results keyed by the client-provided idempotency key. When a duplicate request is
 * detected (same key, function, and arguments), the cached result is returned instead
 * of re-executing the function. Prevents accidental duplicate operations like double
 * payments or duplicate resource creation during network retries.
 *
 * Request options:
 * - key: Unique idempotency key for deduplication (required)
 * - ttl: Requested cache duration with value and unit (optional)
 *
 * Response data:
 * - key: Echoed idempotency key
 * - status: processed, cached, processing, or conflict
 * - original_request_id: Request ID that first processed this key
 * - cached_at: ISO 8601 timestamp when result was cached (if replayed)
 * - expires_at: ISO 8601 timestamp when cached result expires
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/idempotency
 */
final class IdempotencyExtension extends AbstractExtension
{
    /**
     * Idempotency status values.
     */
    public const string STATUS_PROCESSED = 'processed';

    public const string STATUS_CACHED = 'cached';

    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_CONFLICT = 'conflict';

    /**
     * Default TTL in seconds (24 hours).
     */
    private const int DEFAULT_TTL_SECONDS = 86_400;

    /**
     * Maximum TTL in seconds (30 days).
     */
    private const int MAX_TTL_SECONDS = 2_592_000;

    /**
     * Processing lock TTL in seconds.
     */
    private const int LOCK_TTL_SECONDS = 30;

    /**
     * Context for current request (set in onExecutingFunction).
     *
     * @var null|array{key: string, cache_key: string, lock: Lock}
     */
    private ?array $context = null;

    /**
     * Create a new extension instance.
     *
     * @param CacheRepository $cache      Cache repository for storing idempotency records and locks.
     *                                    Used to track processed requests and prevent concurrent processing
     *                                    of the same idempotency key across multiple server instances.
     * @param int             $defaultTtl Default cache TTL in seconds (default: 24 hours). Determines how
     *                                    long cached results are retained before expiration. Clients can
     *                                    override this per-request using the ttl option.
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $defaultTtl = self::DEFAULT_TTL_SECONDS,
    ) {}

    /**
     * Release lock on destruction if context exists.
     *
     * Safeguard to ensure locks are released even if the normal flow is interrupted.
     * The lock has a TTL so it will expire anyway, but this prevents unnecessary waiting.
     */
    public function __destruct()
    {
        if ($this->context !== null && isset($this->context['lock'])) {
            $lock = $this->context['lock'];
            if ($lock instanceof Lock) {
                $lock->release();
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::Idempotency->value;
    }

    /**
     * {@inheritDoc}
     */
    #[Override()]
    public function getSubscribedEvents(): array
    {
        return [
            ExecutingFunction::class => [
                'priority' => 25,
                'method' => 'onExecutingFunction',
            ],
            FunctionExecuted::class => [
                'priority' => 25,
                'method' => 'onFunctionExecuted',
            ],
        ];
    }

    /**
     * Check for cached result or acquire processing lock.
     *
     * Validates the idempotency key, checks for existing cached results, detects
     * conflicts (same key with different arguments), and acquires a processing lock
     * to prevent concurrent execution of the same idempotency key.
     *
     * @param ExecutingFunction $event Event containing request and extension data
     */
    public function onExecutingFunction(ExecutingFunction $event): void
    {
        $key = $this->getIdempotencyKey($event->extension->options);

        if ($key === null) {
            $event->setResponse(ResponseData::error(
                new ErrorData(
                    code: ErrorCode::InvalidArguments,
                    message: 'Idempotency key is required',
                ),
                $event->request->id,
            ));
            $event->stopPropagation();

            return;
        }

        $cacheKey = $this->buildCacheKey($key, $event->request->call->function, $event->request->call->version);
        $argumentsHash = $this->hashArguments($event->request->call->arguments);

        // Check for existing cached result
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            assert(is_array($cached), 'Cached value must be an array');

            /** @var array<string, mixed> $cached */
            $event->setResponse($this->handleCachedResult($event, $key, $cached, $argumentsHash));
            $event->stopPropagation();

            return;
        }

        // Use Laravel's atomic lock acquisition
        $lockKey = $cacheKey.':lock';
        $lock = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);

        try {
            // Try to acquire lock (non-blocking)
            if (!$lock->get()) {
                // Someone else has the lock
                $event->setResponse($this->buildProcessingResponse($event, $key));
                $event->stopPropagation();

                return;
            }

            // Double-check cache after acquiring lock (handles race condition)
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                $lock->release();
                assert(is_array($cached), 'Cached value must be an array');

                /** @var array<string, mixed> $cached */
                $event->setResponse($this->handleCachedResult($event, $key, $cached, $argumentsHash));
                $event->stopPropagation();

                return;
            }

            // Store context for onFunctionExecuted
            $this->context = [
                'key' => $key,
                'cache_key' => $cacheKey,
                'lock' => $lock, // Store lock to release later
            ];
        } catch (\Throwable $e) {
            $lock->release();

            throw $e;
        }
    }

    /**
     * Cache the result after successful execution.
     *
     * Stores the response in the cache with the idempotency key, releases the
     * processing lock, and enriches the response with idempotency metadata indicating
     * the request was processed and the result was cached.
     *
     * @param FunctionExecuted $event Event containing request and response data
     */
    public function onFunctionExecuted(FunctionExecuted $event): void
    {
        if ($this->context === null) {
            return;
        }

        $key = $this->context['key'];
        $cacheKey = $this->context['cache_key'];
        $lock = $this->context['lock'];
        $ttl = $this->getTtl($event->extension->options);
        $expiresAt = now()->addSeconds($ttl);

        // Cache the result
        $cacheEntry = [
            'response' => $event->getResponse()->toArray(),
            'original_request_id' => $event->request->id,
            'arguments_hash' => $this->hashArguments($event->request->call->arguments),
            'cached_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];

        $this->cache->put($cacheKey, $cacheEntry, $ttl);

        // Release lock
        if ($lock instanceof Lock) {
            $lock->release();
        }

        // Add idempotency metadata to response
        $extensions = $event->getResponse()->extensions ?? [];
        $extensions[] = ExtensionData::response(ExtensionUrn::Idempotency->value, [
            'key' => $key,
            'status' => self::STATUS_PROCESSED,
            'original_request_id' => $event->request->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        $event->setResponse(
            new ResponseData(
                protocol: $event->getResponse()->protocol,
                id: $event->getResponse()->id,
                result: $event->getResponse()->result,
                errors: $event->getResponse()->errors,
                extensions: $extensions,
                meta: $event->getResponse()->meta,
            ),
        );

        $this->context = null;
    }

    /**
     * Get the idempotency key from extension options.
     *
     * Extracts the client-provided idempotency key from the request options.
     * Returns null if no key was provided, which will trigger a validation error.
     *
     * @param  null|array<string, mixed> $options Extension options from request
     * @return null|string               The idempotency key, or null if not provided
     */
    public function getIdempotencyKey(?array $options): ?string
    {
        $key = $options['key'] ?? null;

        if (!is_string($key)) {
            return null;
        }

        try {
            return $this->validateIdempotencyKey($key);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Validate the idempotency key format and characters.
     *
     * Ensures the key is not empty, within size limits, and contains only safe
     * characters to prevent cache key injection, protocol injection, and DoS attacks.
     *
     * @param  string $key The idempotency key to validate
     * @return string The validated key
     *
     * @throws \InvalidArgumentException If the key is invalid
     */
    private function validateIdempotencyKey(string $key): string
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Idempotency key cannot be empty');
        }

        if (mb_strlen($key) > 255) {
            throw new \InvalidArgumentException('Idempotency key exceeds maximum length of 255 characters');
        }

        // Only allow safe characters to prevent injection attacks
        if (!\preg_match('/^[a-zA-Z0-9\-_:.]+$/', $key)) {
            throw new \InvalidArgumentException(
                'Idempotency key contains invalid characters',
            );
        }

        return $key;
    }

    /**
     * Get TTL from extension options or use default.
     *
     * Converts the TTL duration object (value and unit) into seconds. Supports
     * second, minute, hour, and day units. Falls back to the default TTL if
     * not specified in the request options. Enforces maximum TTL limit.
     *
     * @param  null|array<string, mixed> $options Extension options from request
     * @return int                       TTL in seconds
     *
     * @throws \InvalidArgumentException If TTL exceeds maximum or is not positive
     */
    public function getTtl(?array $options): int
    {
        if (!isset($options['ttl'])) {
            return $this->defaultTtl;
        }

        $ttl = $options['ttl'];
        assert(is_array($ttl), 'TTL must be an array');
        $rawValue = $ttl['value'] ?? 0;
        $value = is_int($rawValue) ? $rawValue : (is_numeric($rawValue) ? (int) $rawValue : 0);
        $rawUnit = $ttl['unit'] ?? 'second';
        $unit = is_string($rawUnit) ? $rawUnit : 'second';

        $seconds = match ($unit) {
            'second' => $value,
            'minute' => $value * 60,
            'hour' => $value * 3_600,
            'day' => $value * 86_400,
            default => $value,
        };

        if ($seconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException(
                'TTL cannot exceed '.self::MAX_TTL_SECONDS.' seconds (30 days)',
            );
        }

        if ($seconds <= 0) {
            throw new \InvalidArgumentException('TTL must be positive');
        }

        return $seconds;
    }

    /**
     * Handle a cached result - either return it or detect conflict.
     *
     * Compares the arguments hash of the cached result with the current request.
     * If they match, returns the cached result with updated response ID. If they
     * differ, returns a conflict error indicating the same key was used with
     * different arguments (preventing silent data corruption).
     *
     * @param  ExecutingFunction    $event         Current event with request data
     * @param  string               $key           Idempotency key from request
     * @param  array<string, mixed> $cached        Cached entry containing response and metadata
     * @param  string               $argumentsHash SHA256 hash of current request arguments
     * @return ResponseData         Cached response or conflict error response
     */
    private function handleCachedResult(
        ExecutingFunction $event,
        string $key,
        array $cached,
        string $argumentsHash,
    ): ResponseData {
        // Check for argument conflict
        if ($cached['arguments_hash'] !== $argumentsHash) {
            return $this->buildConflictResponse($event, $key, $cached);
        }

        // Return cached result
        assert(is_array($cached['response']), 'Cached response must be an array');

        /** @var array<string, mixed> $cachedResponseArray */
        $cachedResponseArray = $cached['response'];
        $cachedResponse = ResponseData::from($cachedResponseArray);

        // Replace extensions with idempotency metadata
        $extensions = [
            ExtensionData::response(ExtensionUrn::Idempotency->value, [
                'key' => $key,
                'status' => self::STATUS_CACHED,
                'original_request_id' => $cached['original_request_id'],
                'cached_at' => $cached['cached_at'],
                'expires_at' => $cached['expires_at'],
            ]),
        ];

        return new ResponseData(
            protocol: $cachedResponse->protocol,
            id: $event->request->id, // Use current request ID
            result: $cachedResponse->result,
            errors: $cachedResponse->errors,
            extensions: $extensions,
            meta: $cachedResponse->meta,
        );
    }

    /**
     * Build a conflict response.
     *
     * Creates an error response indicating the idempotency key was already used
     * with different arguments. Prevents silent data corruption by rejecting
     * the request rather than processing it with conflicting data.
     *
     * @param  ExecutingFunction    $event  Current event with request data
     * @param  string               $key    Idempotency key that caused conflict
     * @param  array<string, mixed> $cached Cached entry with original arguments hash
     * @return ResponseData         Error response with conflict details
     */
    private function buildConflictResponse(ExecutingFunction $event, string $key, array $cached): ResponseData
    {
        return new ResponseData(
            protocol: $event->request->protocol,
            id: $event->request->id,
            result: null,
            errors: [
                new ErrorData(
                    code: ErrorCode::IdempotencyConflict,
                    message: 'Idempotency key already used with different arguments',
                    details: [
                        'key' => $key,
                        'original_arguments_hash' => $cached['arguments_hash'],
                    ],
                ),
            ],
            extensions: [
                ExtensionData::response(ExtensionUrn::Idempotency->value, [
                    'key' => $key,
                    'status' => self::STATUS_CONFLICT,
                    'original_request_id' => $cached['original_request_id'],
                ]),
            ],
        );
    }

    /**
     * Build a processing response.
     *
     * Creates an error response indicating a previous request with the same
     * idempotency key is still being processed. Includes retry-after guidance
     * to help clients implement exponential backoff.
     *
     * @param  ExecutingFunction $event Current event with request data
     * @param  string            $key   Idempotency key being processed
     * @return ResponseData      Error response with retry guidance
     */
    private function buildProcessingResponse(ExecutingFunction $event, string $key): ResponseData
    {
        return ResponseData::error(
            new ErrorData(
                code: ErrorCode::IdempotencyProcessing,
                message: 'Previous request with this key is still processing',
                details: [
                    'key' => $key,
                    'retry_after' => ['value' => 1, 'unit' => 'second'],
                ],
            ),
            $event->request->id,
        );
    }

    /**
     * Build a cache key from idempotency key, function, and version.
     *
     * Creates a unique cache key by combining the idempotency key with the
     * function name and version. Uses SHA256 to ensure consistent key length
     * and prevent cache key collisions across different functions.
     *
     * @param  string      $key      Client-provided idempotency key
     * @param  string      $function Function name being executed
     * @param  null|string $version  Function version, or null for latest
     * @return string      SHA256-based cache key with namespace prefix
     */
    private function buildCacheKey(string $key, string $function, ?string $version): string
    {
        $hash = hash('sha256', $key.'|'.$function.'|'.($version ?? 'latest'));

        return 'forrst_idempotency:'.$hash;
    }

    /**
     * Hash request arguments for conflict detection.
     *
     * Creates a deterministic hash of the request arguments to detect when
     * the same idempotency key is used with different arguments. Uses JSON
     * encoding for stable serialization and SHA256 for the hash.
     *
     * @param  null|array<string, mixed> $arguments Request arguments to hash
     * @return string                    SHA256 hash of the JSON-encoded arguments with algorithm prefix
     */
    private function hashArguments(?array $arguments): string
    {
        $encoded = json_encode($arguments ?? []);
        assert($encoded !== false, 'JSON encoding failed');

        return 'sha256:'.hash('sha256', $encoded);
    }
}

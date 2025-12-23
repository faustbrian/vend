<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\AtomicLock;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\ProvidesFunctionsInterface;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Exceptions\LockAcquisitionFailedException;
use Cline\Forrst\Exceptions\LockKeyRequiredException;
use Cline\Forrst\Exceptions\LockNotFoundException;
use Cline\Forrst\Exceptions\LockOwnershipMismatchException;
use Cline\Forrst\Exceptions\LockTimeoutException;
use Cline\Forrst\Exceptions\LockTtlExceedsMaximumException;
use Cline\Forrst\Exceptions\LockTtlRequiredException;
use Cline\Forrst\Exceptions\UnauthorizedException;
use Cline\Forrst\Extensions\AbstractExtension;
use Cline\Forrst\Extensions\AtomicLock\Functions\LockForceReleaseFunction;
use Cline\Forrst\Extensions\AtomicLock\Functions\LockReleaseFunction;
use Cline\Forrst\Extensions\AtomicLock\Functions\LockStatusFunction;
use Cline\Forrst\Extensions\ExtensionUrn;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException as LaravelLockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Override;

use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function now;

/**
 * Atomic lock extension handler.
 *
 * Enables distributed locking to prevent concurrent access to shared resources.
 * Unlike idempotency (which prevents duplicate processing of the same request),
 * atomic locks block all requests targeting a locked resource until released.
 *
 * Request options:
 * - key: Lock identifier (required)
 * - ttl: Lock duration with value and unit (required)
 * - scope: 'function' (default) or 'global'
 * - block: Wait timeout for blocking acquisition (optional)
 * - owner: Custom owner token (auto-generated if omitted)
 * - auto_release: Release after function execution (default: true)
 *
 * Response data:
 * - key: Echoed lock key
 * - acquired: Whether lock was acquired
 * - owner: Owner token for cross-process release
 * - scope: Applied scope
 * - expires_at: ISO 8601 timestamp when lock expires
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/atomic-lock
 */
final class AtomicLockExtension extends AbstractExtension implements ProvidesFunctionsInterface
{
    /**
     * Default scope for locks.
     */
    public const string SCOPE_FUNCTION = 'function';

    public const string SCOPE_GLOBAL = 'global';

    /**
     * Lock prefix for cache keys.
     */
    private const string LOCK_PREFIX = 'forrst_lock:';

    /**
     * Maximum allowed TTL for locks (24 hours).
     */
    private const int MAX_TTL_SECONDS = 86_400;

    /**
     * Maximum length for lock keys.
     */
    private const int MAX_KEY_LENGTH = 200;

    /**
     * Regex pattern for valid lock keys (alphanumeric, dash, underscore, colon, dot).
     */
    private const string KEY_PATTERN = '/^[a-zA-Z0-9\-_:.]+$/';

    /**
     * Context for current request (set in onExecutingFunction).
     *
     * @var null|array{key: string, full_key: string, scope: string, lock: Lock, owner: string, ttl: int, auto_release: bool, expires_at: string}
     */
    private ?array $context = null;

    /**
     * Authorization callback for administrative operations.
     *
     * @var null|callable(string): bool
     */
    private $authorizationCallback = null;

    /**
     * Get the functions provided by this extension.
     *
     * @return array<int, class-string<FunctionInterface>>
     */
    #[Override()]
    public function functions(): array
    {
        return [
            LockStatusFunction::class,
            LockReleaseFunction::class,
            LockForceReleaseFunction::class,
        ];
    }

    /**
     * Set authorization callback for force release operations.
     *
     * @param callable(string): bool $callback Function receiving lock key, returning bool
     */
    public function setAuthorizationCallback(callable $callback): void
    {
        $this->authorizationCallback = $callback;
    }

    /**
     * {@inheritDoc}
     */
    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::AtomicLock->value;
    }

    /**
     * {@inheritDoc}
     */
    #[Override()]
    public function getSubscribedEvents(): array
    {
        return [
            ExecutingFunction::class => [
                'priority' => 30, // Before idempotency (25)
                'method' => 'onExecutingFunction',
            ],
            FunctionExecuted::class => [
                'priority' => 30,
                'method' => 'onFunctionExecuted',
            ],
        ];
    }

    /**
     * Attempt to acquire lock before function execution.
     *
     * Validates lock options, builds the lock key with appropriate scope,
     * and attempts to acquire the lock (with optional blocking).
     *
     * @param ExecutingFunction $event Event containing request and extension data
     *
     * @throws LockAcquisitionFailedException If lock cannot be acquired immediately
     * @throws LockTimeoutException           If blocking acquisition times out
     */
    public function onExecutingFunction(ExecutingFunction $event): void
    {
        try {
            $options = $event->extension->options ?? [];

            // Validate required options
            $key = $options['key'] ?? null;

            if (!is_string($key) || $key === '') {
                throw LockKeyRequiredException::create();
            }

            // Validate key format and length
            if (\strlen($key) > self::MAX_KEY_LENGTH) {
                throw new \InvalidArgumentException(
                    'Lock key exceeds maximum length of '.self::MAX_KEY_LENGTH.' characters',
                );
            }

            if (!\preg_match(self::KEY_PATTERN, $key)) {
                throw new \InvalidArgumentException(
                    'Lock key contains invalid characters. Only alphanumeric, dash, underscore, colon, and dot allowed',
                );
            }

            // Prevent key injection attacks
            if (\str_contains($key, ':meta:')) {
                throw new \InvalidArgumentException(
                    "Lock key cannot contain ':meta:' sequence (reserved for internal use)",
                );
            }

            $ttlOption = $options['ttl'] ?? null;

            if ($ttlOption === null) {
                throw LockTtlRequiredException::create();
            }

            /** @var array<string, mixed> $ttlArray */
            $ttlArray = is_array($ttlOption) ? $ttlOption : [];
            $ttl = $this->parseDuration($ttlArray);
            $scope = is_string($options['scope'] ?? null) ? $options['scope'] : self::SCOPE_FUNCTION;
            $owner = is_string($options['owner'] ?? null) ? $options['owner'] : Str::uuid()->toString();
            $autoRelease = is_bool($options['auto_release'] ?? null) ? $options['auto_release'] : true;
            $blockOption = $options['block'] ?? null;

            // Build full lock key
            $fullKey = $this->buildLockKey($key, $scope, $event->request->call->function);

            // Create lock instance
            $lock = Cache::lock($fullKey, $ttl, $owner);

            // Attempt acquisition
            if ($blockOption !== null) {
                /** @var array<string, mixed> $blockArray */
                $blockArray = is_array($blockOption) ? $blockOption : [];
                $blockTimeout = $this->parseDuration($blockArray);

                try {
                    $lock->block($blockTimeout);
                } catch (LaravelLockTimeoutException) {
                    throw LockTimeoutException::forKey($key, $scope, $fullKey, $blockArray);
                }
            } elseif (!$lock->get()) {
                throw LockAcquisitionFailedException::forKey($key, $scope, $fullKey);
            }

            // IMMEDIATELY calculate and store metadata BEFORE setting context
            // This prevents race condition where lock exists but metadata doesn't
            $acquiredAt = now()->toIso8601String();
            $expiresAt = now()->addSeconds($ttl)->toIso8601String();
            $this->storeLockMetadata($fullKey, $owner, $acquiredAt, $expiresAt, $ttl);

            // THEN set context for onFunctionExecuted
            $this->context = [
                'key' => $key,
                'full_key' => $fullKey,
                'scope' => $scope,
                'lock' => $lock,
                'owner' => $owner,
                'ttl' => $ttl,
                'auto_release' => $autoRelease,
                'acquired_at' => $acquiredAt,
                'expires_at' => $expiresAt,
            ];
        } catch (\Throwable $e) {
            // Ensure context is null on any failure
            $this->context = null;

            // If we acquired a lock but failed afterward, release it
            if (isset($lock) && isset($fullKey)) {
                try {
                    $lock->release();
                    $this->clearLockMetadata($fullKey);
                } catch (\Throwable) {
                    // Ignore release failures during exception handling
                }
            }

            throw $e;
        }
    }

    /**
     * Handle post-execution lock release and response enrichment.
     *
     * If auto_release is enabled, releases the lock after function execution.
     * Adds lock metadata to the response for cross-process release support.
     *
     * @param FunctionExecuted $event Event containing request and response data
     */
    public function onFunctionExecuted(FunctionExecuted $event): void
    {
        if ($this->context === null) {
            return;
        }

        $context = $this->context;
        $this->context = null;

        // Auto-release if configured
        if ($context['auto_release']) {
            try {
                $released = $context['lock']->release();

                if (!$released) {
                    // Log the failure but don't throw - response already generated
                    Log::warning('Failed to auto-release lock', [
                        'lock_key' => $context['full_key'],
                        'owner' => $context['owner'],
                    ]);
                } else {
                    $this->clearLockMetadata($context['full_key']);
                }
            } catch (\Throwable $e) {
                // Log but don't throw - we're in post-execution phase
                Log::warning('Exception during auto-release of lock', [
                    'lock_key' => $context['full_key'],
                    'owner' => $context['owner'],
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Add lock metadata to response
        $extensions = $event->getResponse()->extensions ?? [];
        $extensions[] = ExtensionData::response(ExtensionUrn::AtomicLock->value, [
            'key' => $context['key'],
            'acquired' => true,
            'owner' => $context['owner'],
            'scope' => $context['scope'],
            'expires_at' => $context['expires_at'],
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
    }

    /**
     * Release a lock with ownership verification.
     *
     * Used by the forrst.locks.release system function. Restores a lock
     * using the key and owner token, then releases it.
     *
     * @param string $key   The full lock key (with scope prefix)
     * @param string $owner The owner token from acquisition
     *
     * @throws LockNotFoundException          If lock does not exist
     * @throws LockOwnershipMismatchException If owner does not match
     * @return bool                           True if released successfully
     */
    public function releaseLock(string $key, string $owner): bool
    {
        // Check if lock exists via metadata
        $storedOwner = Cache::get($this->metadataKey($key, 'owner'));

        if ($storedOwner === null) {
            throw LockNotFoundException::forKey($key);
        }

        // Verify ownership
        if ($storedOwner !== $owner) {
            throw LockOwnershipMismatchException::forKey($key);
        }

        // Restore and release the lock
        $restoredLock = Cache::restoreLock($key, $owner);
        $released = $restoredLock->release();

        // Clear metadata
        $this->clearLockMetadata($key);

        return $released;
    }

    /**
     * Force release a lock without ownership check.
     *
     * Used by the forrst.locks.forceRelease system function. Releases a lock
     * regardless of ownership. This is an administrative operation.
     *
     * @param string $key The full lock key (with scope prefix)
     *
     * @throws LockNotFoundException If lock does not exist
     * @throws UnauthorizedException If authorization check fails
     * @return bool                  True if released successfully
     */
    public function forceReleaseLock(string $key): bool
    {
        // Authorization check
        if ($this->authorizationCallback !== null && !($this->authorizationCallback)($key)) {
            throw UnauthorizedException::create('Force release operation requires administrative privileges');
        }

        // Check if lock exists via metadata
        $storedOwner = Cache::get($this->metadataKey($key, 'owner'));

        if ($storedOwner === null) {
            throw LockNotFoundException::forKey($key);
        }

        Cache::lock($key)->forceRelease();
        $this->clearLockMetadata($key);

        return true;
    }

    /**
     * Get the status of a lock.
     *
     * Used by the forrst.locks.status system function. Returns information
     * about a lock including whether it's held, its owner, and expiration.
     *
     * @param  string               $key The full lock key (with scope prefix)
     * @return array<string, mixed> Lock status information
     */
    public function getLockStatus(string $key): array
    {
        // Check lock status via metadata (avoids race condition of acquiring to test)
        $owner = Cache::get($this->metadataKey($key, 'owner'));

        if ($owner === null) {
            return [
                'key' => $key,
                'locked' => false,
            ];
        }

        $acquiredAt = Cache::get($this->metadataKey($key, 'acquired_at'));
        $expiresAt = Cache::get($this->metadataKey($key, 'expires_at'));

        $ttlRemaining = null;

        if ($expiresAt !== null && is_string($expiresAt)) {
            $ttlRemaining = (int) now()->diffInSeconds($expiresAt, false);

            if ($ttlRemaining < 0) {
                $ttlRemaining = 0;
            }
        }

        return [
            'key' => $key,
            'locked' => true,
            'owner' => $owner,
            'acquired_at' => $acquiredAt,
            'expires_at' => $expiresAt,
            'ttl_remaining' => $ttlRemaining,
        ];
    }

    /**
     * Build a lock key with scope prefix.
     *
     * @param  string $key      Client-provided lock key
     * @param  string $scope    Scope type (function or global)
     * @param  string $function Function name for function-scoped locks
     * @return string Full lock key with prefix
     */
    public function buildLockKey(string $key, string $scope, string $function): string
    {
        if ($scope === self::SCOPE_FUNCTION) {
            return self::LOCK_PREFIX.$function.':'.$key;
        }

        return self::LOCK_PREFIX.$key;
    }

    /**
     * Parse a duration object into seconds.
     *
     * @param  array<string, mixed> $duration Duration with value and unit
     * @throws LockTtlExceedsMaximumException If TTL exceeds maximum allowed
     * @return int                  Duration in seconds
     */
    private function parseDuration(array $duration): int
    {
        $rawValue = $duration['value'] ?? 0;
        $value = is_int($rawValue) ? $rawValue : (is_numeric($rawValue) ? (int) $rawValue : 0);
        $unit = $duration['unit'] ?? 'second';

        if (!is_string($unit)) {
            throw new \InvalidArgumentException('Duration unit must be a string');
        }

        $seconds = match ($unit) {
            'second' => $value,
            'minute' => $value * 60,
            'hour' => $value * 3_600,
            'day' => $value * 86_400,
            default => throw new \InvalidArgumentException(
                "Invalid duration unit: {$unit}. Allowed: second, minute, hour, day",
            ),
        };

        if ($seconds > self::MAX_TTL_SECONDS) {
            throw LockTtlExceedsMaximumException::create($seconds, self::MAX_TTL_SECONDS);
        }

        if ($seconds <= 0) {
            throw new \InvalidArgumentException('TTL must be positive');
        }

        return $seconds;
    }

    /**
     * Store lock metadata in cache.
     *
     * @param string $key        The full lock key
     * @param string $owner      Owner token
     * @param string $acquiredAt Acquisition timestamp
     * @param string $expiresAt  Expiration timestamp
     * @param int    $ttl        TTL in seconds
     */
    private function storeLockMetadata(
        string $key,
        string $owner,
        string $acquiredAt,
        string $expiresAt,
        int $ttl,
    ): void {
        // Add 10 seconds buffer to ensure metadata outlives the lock slightly
        // This prevents metadata from expiring before lock, but ensures cleanup
        $metadataTtl = $ttl + 10;

        Cache::put($this->metadataKey($key, 'owner'), $owner, $metadataTtl);
        Cache::put($this->metadataKey($key, 'acquired_at'), $acquiredAt, $metadataTtl);
        Cache::put($this->metadataKey($key, 'expires_at'), $expiresAt, $metadataTtl);
    }

    /**
     * Clear lock metadata from cache.
     *
     * @param string $key The full lock key
     */
    private function clearLockMetadata(string $key): void
    {
        Cache::forget($this->metadataKey($key, 'owner'));
        Cache::forget($this->metadataKey($key, 'acquired_at'));
        Cache::forget($this->metadataKey($key, 'expires_at'));
    }

    /**
     * Build a metadata key for a lock.
     *
     * @param  string $key   The full lock key
     * @param  string $field The metadata field name
     * @return string The cache key for the metadata field
     */
    private function metadataKey(string $key, string $field): string
    {
        return $key.':meta:'.$field;
    }
}

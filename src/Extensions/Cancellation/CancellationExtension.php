<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Cancellation;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\ProvidesFunctionsInterface;
use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Events\RequestValidated;
use Cline\Forrst\Extensions\AbstractExtension;
use Cline\Forrst\Extensions\Cancellation\Functions\CancelFunction;
use Cline\Forrst\Extensions\ExtensionUrn;
use Illuminate\Support\Facades\Cache;
use Override;

use function is_string;

/**
 * Cancellation extension handler.
 *
 * Enables explicit request cancellation for synchronous requests. Clients
 * can include a cancellation token in the request and send a separate
 * cancel request using that token.
 *
 * Request options:
 * - token: string - Unique cancellation token
 *
 * Cancel via forrst.cancel function:
 * - arguments: {token: string}
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/specs/forrst/extensions/cancellation
 */
final class CancellationExtension extends AbstractExtension implements ProvidesFunctionsInterface
{
    /**
     * Cache prefix for cancellation tokens.
     */
    private const string CACHE_PREFIX = 'forrst:cancel:';

    /**
     * Default token TTL in seconds.
     */
    private const int DEFAULT_TTL = 300;

    /**
     * Maximum token TTL in seconds (1 hour).
     */
    private const int MAX_TTL = 3_600;

    /**
     * Create a new cancellation extension instance.
     *
     * @param int $tokenTtl Token time-to-live in seconds. Defines how long cancellation
     *                      tokens remain active in cache before expiring. Should exceed
     *                      typical request processing time to prevent premature cleanup.
     *                      Maximum allowed: 3600 seconds (1 hour).
     *
     * @throws \InvalidArgumentException If TTL exceeds maximum or is negative
     */
    public function __construct(
        private int $tokenTtl = self::DEFAULT_TTL,
    ) {
        if ($tokenTtl <= 0) {
            throw new \InvalidArgumentException('Token TTL must be positive');
        }

        if ($tokenTtl > self::MAX_TTL) {
            throw new \InvalidArgumentException(
                'Token TTL cannot exceed '.self::MAX_TTL.' seconds',
            );
        }

        $this->tokenTtl = $tokenTtl;
    }

    /**
     * Get the functions provided by this extension.
     *
     * @return array<int, class-string<FunctionInterface>>
     */
    #[Override()]
    public function functions(): array
    {
        return [
            CancelFunction::class,
        ];
    }

    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::Cancellation->value;
    }

    #[Override()]
    public function getSubscribedEvents(): array
    {
        return [
            RequestValidated::class => [
                'priority' => 5,
                'method' => 'onRequestValidated',
            ],
            ExecutingFunction::class => [
                'priority' => 5,
                'method' => 'onExecutingFunction',
            ],
            FunctionExecuted::class => [
                'priority' => 5,
                'method' => 'onFunctionExecuted',
            ],
        ];
    }

    /**
     * Register cancellation token on request validation.
     *
     * Validates the cancellation token is provided and non-empty, then registers
     * it as active in cache. Returns error response if token validation fails.
     *
     * @param RequestValidated $event Request validation event with extension data
     */
    public function onRequestValidated(RequestValidated $event): void
    {
        $extension = $event->request->getExtension(ExtensionUrn::Cancellation->value);

        if (!$extension instanceof ExtensionData) {
            return;
        }

        $token = $extension->options['token'] ?? null;

        if (!is_string($token) || $token === '') {
            $event->setResponse(ResponseData::error(
                new ErrorData(
                    code: ErrorCode::InvalidArguments,
                    message: 'Cancellation token is required',
                ),
                $event->request->id,
            ));
            $event->stopPropagation();

            return;
        }

        try {
            $validToken = $this->validateToken($token);
        } catch (\InvalidArgumentException $e) {
            $event->setResponse(ResponseData::error(
                new ErrorData(
                    code: ErrorCode::InvalidArguments,
                    message: $e->getMessage(),
                ),
                $event->request->id,
            ));
            $event->stopPropagation();

            return;
        }

        // Register the token as active (not cancelled)
        Cache::put(self::CACHE_PREFIX.$validToken, 'active', $this->tokenTtl);
    }

    /**
     * Check cancellation status before function execution.
     *
     * Looks up the cancellation token status in cache. If marked as cancelled,
     * stops propagation and returns CANCELLED error response. Cleans up token
     * after cancellation is detected. Uses locks to prevent race conditions.
     *
     * @param ExecutingFunction $event Function execution event with extension data
     */
    public function onExecutingFunction(ExecutingFunction $event): void
    {
        $extension = $event->extension;

        $token = $extension->options['token'] ?? null;

        if (!is_string($token) || $token === '') {
            return;
        }

        $key = self::CACHE_PREFIX.$token;
        $lock = Cache::lock($key.':lock', 1);

        try {
            $lock->block(1);

            $status = Cache::get($key);

            if ($status === 'cancelled') {
                Cache::forget($key);

                $event->setResponse(ResponseData::error(
                    new ErrorData(
                        code: ErrorCode::Cancelled,
                        message: 'Request was cancelled by client',
                    ),
                    $event->request->id,
                ));
                $event->stopPropagation();
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Clean up token after successful function execution.
     *
     * Removes the token from cache after the request completes successfully.
     * Prevents memory leaks by ensuring tokens don't accumulate in cache.
     *
     * @param FunctionExecuted $event Event containing completed request
     */
    public function onFunctionExecuted(FunctionExecuted $event): void
    {
        $extension = $event->request->getExtension(ExtensionUrn::Cancellation->value);

        if (!$extension instanceof ExtensionData) {
            return;
        }

        $token = $extension->options['token'] ?? null;

        if (is_string($token) && $token !== '') {
            $this->cleanup($token);
        }
    }

    /**
     * Cancel a request by its token.
     *
     * Marks the token as cancelled in cache, signaling to the executing request
     * to abort. Safe to call multiple times for the same token. Returns false
     * if token doesn't exist or has expired.
     *
     * @param string $token Cancellation token from original request
     *
     * @return bool True if cancellation was successful, false if token not found
     */
    public function cancel(string $token): bool
    {
        $key = self::CACHE_PREFIX.$token;
        $status = Cache::get($key);

        if ($status === null) {
            // Token doesn't exist or expired
            return false;
        }

        if ($status === 'cancelled') {
            // Already cancelled
            return true;
        }

        // Mark as cancelled
        Cache::put($key, 'cancelled', $this->tokenTtl);

        return true;
    }

    /**
     * Validate cancellation token format.
     *
     * @param string $token Token to validate
     *
     * @throws \InvalidArgumentException If token is invalid
     *
     * @return string Validated token
     */
    private function validateToken(string $token): string
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Cancellation token cannot be empty');
        }

        if (\strlen($token) > 100) {
            throw new \InvalidArgumentException('Cancellation token exceeds maximum length of 100 characters');
        }

        // Only allow alphanumeric, dash, underscore (UUID-like format recommended)
        if (!\preg_match('/^[a-zA-Z0-9\-_]+$/', $token)) {
            throw new \InvalidArgumentException(
                'Cancellation token contains invalid characters. Only alphanumeric, dash, and underscore allowed.',
            );
        }

        return $token;
    }

    /**
     * Check if a token is cancelled.
     *
     * @param string $token Cancellation token to check
     *
     * @return bool True if token is marked as cancelled
     */
    public function isCancelled(string $token): bool
    {
        return Cache::get(self::CACHE_PREFIX.$token) === 'cancelled';
    }

    /**
     * Check if a token is active.
     *
     * A token is active if it exists in cache and has not been cancelled yet.
     *
     * @param string $token Cancellation token to check
     *
     * @return bool True if token is registered and not cancelled
     */
    public function isActive(string $token): bool
    {
        return Cache::get(self::CACHE_PREFIX.$token) === 'active';
    }

    /**
     * Clean up a token after request completion.
     *
     * Removes the token from cache. Should be called after request completes
     * (successfully, with error, or via cancellation) to prevent memory leaks.
     *
     * @param string $token Cancellation token to remove
     */
    public function cleanup(string $token): void
    {
        Cache::forget(self::CACHE_PREFIX.$token);
    }
}

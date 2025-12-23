<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Functions\Concerns;

use Cline\Forrst\Exceptions\CancelledException;
use Cline\Forrst\Extensions\Cancellation\CancellationExtension;
use Cline\Forrst\Extensions\ExtensionUrn;

use function is_string;
use function resolve;

/**
 * Cancellation checking helper trait for Forrst functions.
 *
 * Provides cooperative cancellation support for long-running operations using a
 * CancellationToken-like pattern. Functions periodically check cancellation status
 * and can exit gracefully when clients cancel requests via the cancellation extension.
 *
 * This pattern enables responsive cancellation without forceful process termination,
 * allowing functions to clean up resources and return proper error responses when
 * operations are cancelled mid-execution.
 *
 * **Requirements:**
 * - Host class must have RequestObjectData $requestObject property
 * - Host class must call setRequest() before using cancellation methods
 * - CancellationExtension must be registered in service container
 *
 * @property RequestObjectData $requestObject Required by this trait
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/cancellation
 */
trait InteractsWithCancellation
{
    /**
     * Cached cancellation token to avoid repeated extension resolution.
     */
    private ?string $cachedCancellationToken = null;

    /**
     * Flag indicating whether the cancellation token has been resolved.
     */
    private bool $cancellationTokenResolved = false;

    /**
     * Extract the cancellation token from the current request.
     *
     * Retrieves the cancellation token if the request includes the cancellation
     * extension with a token parameter. Returns null if the extension is not
     * present or the token is not provided.
     *
     * The token is cached after first retrieval to avoid repeated extension
     * resolution during multiple cancellation checks.
     *
     * @return null|string The cancellation token string or null if not provided
     */
    protected function getCancellationToken(): ?string
    {
        if ($this->cancellationTokenResolved) {
            return $this->cachedCancellationToken;
        }

        $this->cancellationTokenResolved = true;

        $extension = $this->requestObject->getExtension(ExtensionUrn::Cancellation);

        if ($extension === null) {
            $this->cachedCancellationToken = null;

            return null;
        }

        $token = $extension->options['token'] ?? null;

        $this->cachedCancellationToken = is_string($token) ? $token : null;

        return $this->cachedCancellationToken;
    }

    /**
     * Check if cancellation has been requested for this operation.
     *
     * Returns true when both conditions are met:
     * 1. The request includes a cancellation token
     * 2. That token has been marked as cancelled via forrst.cancel
     *
     * Call this method periodically during long-running operations to implement
     * cooperative cancellation, allowing graceful exit when clients cancel requests.
     *
     * @return bool True if cancellation has been requested by the client
     */
    protected function isCancellationRequested(): bool
    {
        $token = $this->getCancellationToken();

        if ($token === null) {
            return false;
        }

        return resolve(CancellationExtension::class)->isCancelled($token);
    }

    /**
     * Throw an exception if cancellation has been requested.
     *
     * Convenience method that checks cancellation status and throws a
     * CancelledException if the operation has been cancelled. Use this at
     * checkpoints in long-running operations for automatic cancellation handling.
     *
     * @throws CancelledException When cancellation has been requested by the client
     */
    protected function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw CancelledException::create();
        }
    }
}

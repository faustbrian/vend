<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Cline\Forrst\Contracts\ExtensionInterface;

/**
 * Base implementation for Forrst extension handlers.
 *
 * Provides sensible defaults for extension behavior while requiring subclasses
 * to implement the core identification method (getUrn). Extensions add optional
 * capabilities to the Forrst protocol such as caching, async operations, deadlines,
 * and deprecation warnings.
 *
 * Default behavior:
 * - Opt-in mode: extension only runs when explicitly requested by clients
 * - Fatal errors: extension failures stop request processing
 * - No event subscriptions: subclasses must override getSubscribedEvents
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/
 */
abstract class AbstractExtension implements ExtensionInterface
{
    /**
     * Get the unique URN identifier for this extension.
     *
     * Subclasses must implement this to return their unique identifier used
     * in extension discovery and request routing.
     *
     * @return string Extension URN (e.g., 'forrst.ext.async')
     */
    abstract public function getUrn(): string;

    /**
     * Determine if extension runs on all requests.
     *
     * By default, extensions are opt-in and only run when explicitly requested
     * in the request's extensions array. Override to return true for extensions
     * that should run globally (e.g., tracing, monitoring).
     *
     * @return bool False by default (opt-in mode)
     */
    public function isGlobal(): bool
    {
        return false;
    }

    /**
     * Determine if extension errors should fail the request.
     *
     * By default, extension errors are fatal and stop request processing. Override
     * to return false for extensions where errors should be logged but allow the
     * request to continue (e.g., optional caching, non-critical monitoring).
     *
     * @return bool True by default (errors are fatal)
     */
    public function isErrorFatal(): bool
    {
        return true;
    }

    /**
     * Get event subscriptions for this extension.
     *
     * Subclasses should override this to subscribe to lifecycle events such as
     * RequestValidated, ExecutingFunction, FunctionExecuted, or SendingResponse.
     * Each subscription includes a priority (lower runs earlier) and method name.
     *
     * @return array<class-string, array{priority: int, method: string}> Event subscriptions
     */
    public function getSubscribedEvents(): array
    {
        return [];
    }

    /**
     * Get additional capability metadata.
     *
     * Subclasses can override this to provide documentation URLs, version info,
     * or other metadata for inclusion in capability responses.
     *
     * @return array<string, mixed> Additional metadata
     */
    protected function getCapabilityMetadata(): array
    {
        return [];
    }

    /**
     * Export extension capabilities for discovery.
     *
     * Returns the extension's URN for inclusion in server capabilities responses.
     * Subclasses can override getCapabilityMetadata() to include additional information.
     *
     * @return array{urn: string, documentation?: string} Capability information
     *
     * @throws \RuntimeException If URN is empty or has invalid format
     */
    final public function toCapabilities(): array
    {
        $urn = $this->getUrn();

        if (empty($urn)) {
            throw new \RuntimeException(sprintf(
                'Extension %s returned empty URN from getUrn()',
                static::class
            ));
        }

        if (!str_starts_with($urn, 'forrst.ext.')) {
            throw new \RuntimeException(sprintf(
                'Invalid URN format "%s" from %s: must start with "forrst.ext."',
                $urn,
                static::class
            ));
        }

        return array_merge(
            ['urn' => $urn],
            $this->getCapabilityMetadata()
        );
    }
}

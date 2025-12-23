<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Cline\Forrst\Contracts\ExtensionInterface;

use function array_keys;
use function array_map;
use function array_values;

/**
 * Registry for Forrst extensions.
 *
 * Manages registration and retrieval of extension handlers. Extensions are
 * indexed by URN for fast lookup during request processing. Provides capability
 * export for server discovery responses.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/
 */
final class ExtensionRegistry
{
    /**
     * Registered extension handlers.
     *
     * @var array<string, ExtensionInterface>
     */
    private array $extensions = [];

    /**
     * Register an extension handler.
     *
     * Indexes the extension by its URN for fast lookup. If an extension with
     * the same URN is already registered, it will be replaced.
     *
     * Validates that all event subscriptions reference valid, callable methods.
     *
     * @param ExtensionInterface $extension Extension instance to register
     *
     * @throws \InvalidArgumentException If event subscription configuration is invalid
     */
    public function register(ExtensionInterface $extension): void
    {
        // Validate event subscriptions
        foreach ($extension->getSubscribedEvents() as $eventClass => $config) {
            // Validate event class exists
            if (!class_exists($eventClass)) {
                throw new \InvalidArgumentException(
                    sprintf('Event class %s does not exist', $eventClass)
                );
            }

            // Validate subscription config structure
            if (!isset($config['priority']) || !isset($config['method'])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Event subscription for %s must include "priority" and "method" keys',
                        $eventClass
                    )
                );
            }

            // Validate handler method exists and is callable
            $method = $config['method'];
            if (!method_exists($extension, $method)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Extension %s references non-existent method %s for event %s',
                        get_class($extension),
                        $method,
                        $eventClass
                    )
                );
            }

            if (!is_callable([$extension, $method])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Method %s::%s is not callable (check visibility)',
                        get_class($extension),
                        $method
                    )
                );
            }

            // Validate priority is an integer
            if (!is_int($config['priority'])) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Priority for %s::%s must be an integer, %s given',
                        $eventClass,
                        $method,
                        gettype($config['priority'])
                    )
                );
            }
        }

        $this->extensions[$extension->getUrn()] = $extension;
    }

    /**
     * Get an extension handler by URN.
     *
     * Returns the registered extension instance or null if not found. Use this
     * to access extension functionality during request processing.
     *
     * @param string $urn Extension URN to lookup
     *
     * @return null|ExtensionInterface Extension handler or null if not registered
     */
    public function get(string $urn): ?ExtensionInterface
    {
        return $this->extensions[$urn] ?? null;
    }

    /**
     * Check if an extension is registered.
     *
     * Fast existence check using URN. Use before calling get() if you need to
     * distinguish between missing extension and null return value.
     *
     * @param string $urn Extension URN to check
     *
     * @return bool True if extension is registered
     */
    public function has(string $urn): bool
    {
        return isset($this->extensions[$urn]);
    }

    /**
     * Get all registered extensions.
     *
     * Returns associative array keyed by URN. Useful for iterating through
     * all extensions to build event subscriptions or capabilities.
     *
     * @return array<string, ExtensionInterface> All registered extensions
     */
    public function all(): array
    {
        return $this->extensions;
    }

    /**
     * Get all registered extension URNs.
     *
     * Returns list of URNs for all registered extensions. Useful for debugging
     * or listing available extensions.
     *
     * @return array<int, string> List of extension URNs
     */
    public function getUrns(): array
    {
        return array_keys($this->extensions);
    }

    /**
     * Get capabilities data for all registered extensions.
     *
     * Converts all registered extensions to capability format for inclusion in
     * server discovery responses. Each extension provides its URN and optional
     * documentation URL.
     *
     * @return array<int, array{urn: string, documentation?: string}> Capability structures
     */
    public function toCapabilities(): array
    {
        return array_map(
            fn (ExtensionInterface $ext): array => $ext->toCapabilities(),
            array_values($this->extensions),
        );
    }

    /**
     * Unregister an extension.
     *
     * Removes the extension from the registry. No-op if URN is not registered.
     * Use when dynamically managing extension lifecycle.
     *
     * @param string $urn Extension URN to remove
     */
    public function unregister(string $urn): void
    {
        unset($this->extensions[$urn]);
    }
}

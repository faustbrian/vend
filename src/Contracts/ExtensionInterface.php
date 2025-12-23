<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Contracts;

use Cline\Forrst\Events\ExtensionEvent;

/**
 * Forrst extension contract interface.
 *
 * Defines the contract for implementing Forrst protocol extensions that add
 * optional capabilities to RPC servers without modifying the core protocol.
 * Extensions hook into the request lifecycle via event subscriptions.
 *
 * Extensions add optional capabilities to Forrst without modifying the core protocol.
 * Each extension subscribes to lifecycle events and processes requests/responses
 * at appropriate points in the request lifecycle.
 *
 * EXTENSION DEPENDENCIES:
 * Extensions may depend on other extensions being registered. Use priorities
 * to ensure correct execution order. Document dependencies in extension
 * documentation.
 *
 * Example: The "async-result-stream" extension depends on both "async" and
 * "stream" extensions being registered. Set priorities accordingly:
 * - async: priority 40
 * - stream: priority 41
 * - async-result-stream: priority 42
 *
 * Lifecycle events (in order):
 * - RequestValidated: After parsing, before function resolution
 * - ExecutingFunction: Before function dispatch (can short-circuit)
 * - FunctionExecuted: After function returns (can modify response)
 * - SendingResponse: Before serialization (final modifications)
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see ExtensionEvent Base event class for all extension events
 * @see https://docs.cline.sh/forrst/extensions/ Extensions overview
 * @see https://docs.cline.sh/forrst/extensions/async Async extension example
 * @see https://docs.cline.sh/forrst/extensions/stream Stream extension example
 */
interface ExtensionInterface
{
    /**
     * Get the extension URN.
     *
     * MUST use Urn::extension() to ensure proper URN format.
     *
     * @example
     * ```php
     * public function getUrn(): string
     * {
     *     return Urn::extension('caching');
     * }
     * ```
     *
     * @return string The URN identifying this extension (e.g., "urn:forrst:ext:caching")
     */
    public function getUrn(): string;

    /**
     * Whether this extension runs on ALL requests.
     *
     * Global extensions (e.g., tracing, metrics) run regardless of whether
     * the client explicitly includes them in request.extensions[].
     *
     * Non-global extensions only run when the client opts in.
     *
     * PERFORMANCE WARNING: Global extensions execute on every request.
     * Ensure handlers are lightweight and add minimal overhead (<1ms).
     * Use non-global mode for expensive operations that clients should
     * opt into explicitly.
     *
     * @return bool True if this extension runs globally
     */
    public function isGlobal(): bool;

    /**
     * Whether errors in this extension should fail the request.
     *
     * If true (fatal): Extension errors propagate and fail the request.
     * If false (non-fatal): Extension errors are logged but processing continues.
     *
     * Use fatal=true for extensions where failure would cause incorrect behavior
     * (e.g., idempotency). Use fatal=false for optional/informational extensions
     * (e.g., deprecation warnings, metrics).
     */
    public function isErrorFatal(): bool;

    /**
     * Get event subscriptions with priorities.
     *
     * Returns a map of event class names to subscription config. Each config
     * must include 'priority' (lower = earlier) and 'method' (handler name).
     *
     * PERFORMANCE: This method may be called multiple times during extension
     * registration. Implementations should return a static array rather than
     * building it dynamically on each call.
     *
     * Priority ranges:
     * - 0-9: Infrastructure (tracing)
     * - 10-19: Fast-fail (deadline)
     * - 20-29: Short-circuit (caching, idempotency)
     * - 30-39: Validation (batch, dry-run)
     * - 40-49: Execution modifiers (priority, async)
     * - 100: Default
     * - 200+: Post-processing (deprecation, quota)
     *
     * @example
     * ```php
     * private const SUBSCRIPTIONS = [
     *     RequestValidated::class => ['priority' => 20, 'method' => 'handleRequestValidated'],
     *     ExecutingFunction::class => ['priority' => 25, 'method' => 'beforeExecution'],
     * ];
     *
     * public function getSubscribedEvents(): array
     * {
     *     return self::SUBSCRIPTIONS;
     * }
     * ```
     *
     * @return array<class-string<ExtensionEvent>, array{priority: int, method: string}>
     */
    public function getSubscribedEvents(): array;

    /**
     * Get extension documentation for capabilities response.
     *
     * @return array{urn: string, documentation?: string}
     */
    public function toCapabilities(): array;
}

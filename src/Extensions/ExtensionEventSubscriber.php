<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Cline\Forrst\Contracts\ExtensionInterface;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\ExtensionEvent;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Events\RequestValidated;
use Cline\Forrst\Events\SendingResponse;
use Cline\Forrst\Exceptions\EventHandlerMethodNotCallableException;
use Cline\Forrst\Exceptions\EventHandlerMethodNotFoundException;
use Cline\Forrst\Exceptions\InvalidEventHandlerMethodException;
use Cline\Forrst\Exceptions\InvalidEventHandlerPriorityException;
use Cline\Forrst\Facades\Server;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Throwable;

use function is_int;
use function is_string;
use function ksort;
use function method_exists;
use function throw_if;

/**
 * Laravel event subscriber for extension lifecycle events.
 *
 * Manages event subscription and dispatch for the extension system using
 * Laravel's native event dispatcher. Extensions register their event
 * subscriptions with priorities, and this subscriber invokes them in
 * priority order (lower = earlier).
 *
 * Features:
 * - Priority-based ordering (0-9 infrastructure, 10-19 fast-fail, etc.)
 * - Global extensions run on all requests
 * - Per-extension error handling (fatal vs non-fatal)
 * - Event propagation control (short-circuit support)
 *
 * @author Brian Faust <brian@cline.sh>
 */
#[Singleton()]
final class ExtensionEventSubscriber
{
    /**
     * Listeners grouped by event name and priority.
     *
     * @var array<class-string<ExtensionEvent>, array<int, list<array{extension: ExtensionInterface, method: string}>>>
     */
    private array $listeners = [];

    /**
     * Whether the listener map has been built.
     */
    private bool $built = false;

    /**
     * Create a new subscriber instance.
     *
     * @param LoggerInterface $logger Logger for recording non-fatal extension errors.
     *                                Uses NullLogger by default to avoid breaking when
     *                                logging isn't configured. Provides visibility into
     *                                extension failures without stopping request processing.
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Register event listeners with Laravel dispatcher.
     *
     * Subscribes to all extension lifecycle events. Laravel will call the
     * corresponding handler methods when events are dispatched.
     *
     * @param Dispatcher $events Laravel event dispatcher instance
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(RequestValidated::class, $this->handleRequestValidated(...));
        $events->listen(ExecutingFunction::class, $this->handleExecutingFunction(...));
        $events->listen(FunctionExecuted::class, $this->handleFunctionExecuted(...));
        $events->listen(SendingResponse::class, $this->handleSendingResponse(...));
    }

    /**
     * Handle RequestValidated event.
     *
     * Dispatches to subscribed extensions for request validation phase.
     * Extensions can perform early validation and short-circuit processing.
     *
     * @param RequestValidated $event Request validation event
     */
    public function handleRequestValidated(RequestValidated $event): void
    {
        $this->dispatch($event);
    }

    /**
     * Handle ExecutingFunction event.
     *
     * Dispatches to subscribed extensions before function execution.
     * Extensions can check preconditions and prevent execution.
     *
     * @param ExecutingFunction $event Function execution event
     */
    public function handleExecutingFunction(ExecutingFunction $event): void
    {
        $this->dispatch($event);
    }

    /**
     * Handle FunctionExecuted event.
     *
     * Dispatches to subscribed extensions after function execution.
     * Extensions can enrich response with metadata.
     *
     * @param FunctionExecuted $event Function executed event
     */
    public function handleFunctionExecuted(FunctionExecuted $event): void
    {
        $this->dispatch($event);
    }

    /**
     * Handle SendingResponse event.
     *
     * Dispatches to subscribed extensions before sending response.
     * Final opportunity for extensions to modify response.
     *
     * @param SendingResponse $event Response sending event
     */
    public function handleSendingResponse(SendingResponse $event): void
    {
        $this->dispatch($event);
    }

    /**
     * Rebuild the listener map.
     *
     * Clears cached listener map and forces rebuild on next dispatch. Call this
     * if extensions are registered after subscriber construction to pick up new
     * subscriptions.
     */
    public function rebuild(): void
    {
        $this->listeners = [];
        $this->built = false;
    }

    /**
     * Dispatch event to subscribed extension listeners.
     *
     * Invokes listeners in priority order (lower number = earlier execution).
     * Only runs extensions that are marked as global or explicitly requested
     * in the request's extensions array. Handles fatal/non-fatal error modes
     * and supports short-circuiting via stopPropagation.
     *
     * @param ExtensionEvent $event Event to dispatch to extensions
     */
    private function dispatch(ExtensionEvent $event): void
    {
        $this->ensureBuilt();

        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        foreach ($this->listeners[$eventClass] as $listeners) {
            foreach ($listeners as $listener) {
                /** @var ExtensionInterface $extension */
                $extension = $listener['extension'];

                // Run if: extension is global OR request explicitly includes it
                $shouldRun = $extension->isGlobal()
                    || $event->request->hasExtension($extension->getUrn());

                if (!$shouldRun) {
                    continue;
                }

                try {
                    $method = $listener['method'];
                    $extension->{$method}($event);
                } catch (Throwable $e) {
                    throw_if($extension->isErrorFatal(), $e);

                    $this->logger->warning('Extension error (non-fatal)', [
                        'extension' => $extension->getUrn(),
                        'event' => $eventClass,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    continue;
                }

                if ($event->isPropagationStopped()) {
                    return;
                }
            }
        }
    }

    /**
     * Ensure listener map has been built.
     *
     * Lazy initialization pattern - builds map on first use. Subsequent calls
     * are no-ops thanks to built flag.
     */
    private function ensureBuilt(): void
    {
        if ($this->built) {
            return;
        }

        $this->buildListenerMap();
        $this->built = true;
    }

    /**
     * Build listener map from registered extensions.
     *
     * Collects event subscriptions from all registered extensions and organizes
     * them by event class and priority. Sorts by priority to ensure lower-priority
     * listeners execute first.
     *
     * Validates each subscription to ensure:
     * - Priority is an integer
     * - Method name is a string
     * - Method exists on the extension class
     * - Method is publicly callable
     *
     * @throws InvalidEventHandlerPriorityException If priority is not an integer
     * @throws InvalidEventHandlerMethodException If method name is not a string
     * @throws EventHandlerMethodNotFoundException If method does not exist
     * @throws EventHandlerMethodNotCallableException If method is not public
     */
    private function buildListenerMap(): void
    {
        $registry = Server::getExtensionRegistry();

        foreach ($registry->all() as $extension) {
            foreach ($extension->getSubscribedEvents() as $eventClass => $config) {
                $priority = $config['priority'];
                $method = $config['method'];
                $extensionUrn = $extension->getUrn();
                $extensionClass = $extension::class;

                $this->validateSubscription(
                    $extension,
                    $extensionUrn,
                    $extensionClass,
                    $eventClass,
                    $priority,
                    $method,
                );

                $this->listeners[$eventClass][$priority][] = [
                    'extension' => $extension,
                    'method' => $method,
                ];
            }
        }

        // Sort each event's listeners by priority (lower = earlier)
        foreach ($this->listeners as &$priorities) {
            ksort($priorities);
        }
    }

    /**
     * Validate a single event subscription configuration.
     *
     * @param ExtensionInterface $extension      Extension instance
     * @param string             $extensionUrn   Extension URN for error messages
     * @param string             $extensionClass Extension class name for error messages
     * @param string             $eventClass     Event class being subscribed to
     * @param mixed              $priority       Priority value (must be int)
     * @param mixed              $method         Method name (must be string and callable)
     *
     * @throws InvalidEventHandlerPriorityException If priority is not an integer
     * @throws InvalidEventHandlerMethodException If method name is not a string
     * @throws EventHandlerMethodNotFoundException If method does not exist
     * @throws EventHandlerMethodNotCallableException If method is not public
     */
    private function validateSubscription(
        ExtensionInterface $extension,
        string $extensionUrn,
        string $extensionClass,
        string $eventClass,
        mixed $priority,
        mixed $method,
    ): void {
        // Validate priority is an integer
        if (!is_int($priority)) {
            throw InvalidEventHandlerPriorityException::forEvent(
                $extensionUrn,
                $eventClass,
                $priority,
            );
        }

        // Validate method is a string
        if (!is_string($method)) {
            throw InvalidEventHandlerMethodException::forEvent(
                $extensionUrn,
                $eventClass,
                $method,
            );
        }

        // Validate method exists on extension
        if (!method_exists($extension, $method)) {
            throw EventHandlerMethodNotFoundException::forEvent(
                $extensionUrn,
                $eventClass,
                $method,
                $extensionClass,
            );
        }

        // Validate method is public (callable)
        $reflection = new ReflectionMethod($extension, $method);

        if (!$reflection->isPublic()) {
            throw EventHandlerMethodNotCallableException::forEvent(
                $extensionUrn,
                $eventClass,
                $method,
                $extensionClass,
            );
        }
    }
}

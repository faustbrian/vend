<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Http\Middleware;

use Cline\Forrst\Contracts\ServerInterface;
use Cline\Forrst\Exceptions\RouteNameRequiredException;
use Cline\Forrst\Repositories\ServerRepository;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

use function assert;
use function throw_if;

/**
 * Bootstraps the Forrst server for each incoming request.
 *
 * Resolves the appropriate server instance based on the route name and binds it
 * to the service container. This enables request-specific server configuration
 * and ensures the correct server handles each Forrst request. The middleware also
 * performs cleanup after response transmission to prevent memory leaks in long-running
 * processes.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 *
 * @psalm-immutable
 */
final readonly class BootServer
{
    /**
     * Create a new server bootstrapping middleware instance.
     *
     * @param Container        $container        Laravel service container instance used for binding
     *                                           the resolved server instance and managing its lifecycle
     *                                           throughout the request. Enables dependency injection
     *                                           of the server interface to downstream components.
     * @param ServerRepository $serverRepository Repository for retrieving server configurations
     *                                           by name, enabling route-specific server resolution
     *                                           and configuration loading based on the route's
     *                                           registered name.
     */
    public function __construct(
        private Container $container,
        private ServerRepository $serverRepository,
    ) {}

    /**
     * Handle the incoming Forrst request and bootstrap the appropriate server.
     *
     * Retrieves the route name from the request, resolves the corresponding server
     * configuration, and binds it to the container for use in downstream handlers.
     * The server instance is bound as a singleton for the request lifetime, ensuring
     * consistent configuration across all components processing the request.
     *
     * @param Request $request The incoming HTTP request containing the route information
     * @param Closure $next    The next middleware in the pipeline
     *
     * @throws RouteNameRequiredException When the route name is missing, preventing server resolution
     *
     * @return Response The response from the next middleware or handler
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Route can be null in several scenarios:
        // 1. Request doesn't match any route (should be caught by 404 handler before this)
        // 2. Route resolver not set (testing/console contexts)
        // 3. Route exists but has no name (misconfiguration)
        // @phpstan-ignore-next-line - PHPStan's conditional return type doesn't account for resolver edge cases
        $routeName = $request->route()?->getName();

        if ($routeName === null) {
            throw RouteNameRequiredException::create();
        }

        $server = $this->serverRepository->findByName($routeName);

        if (!$server instanceof ServerInterface) {
            throw new \RuntimeException(
                sprintf(
                    'Server repository returned invalid instance for route "%s". '.
                    'Expected %s, got %s',
                    $routeName,
                    ServerInterface::class,
                    get_debug_type($server)
                )
            );
        }

        $this->container->instance(ServerInterface::class, $server);

        $response = $next($request);
        assert($response instanceof Response);

        return $response;
    }

    /**
     * Clean up the server instance after the response has been sent.
     *
     * Removes the server instance from the container to prevent memory leaks
     * and ensure fresh server resolution for subsequent requests. This is critical
     * in long-running processes like Laravel Octane or queue workers. Cleanup is
     * skipped during unit tests to maintain test isolation and prevent container
     * state issues between test cases.
     */
    public function terminate(): void
    {
        if (App::runningUnitTests()) {
            return;
        }

        $this->container->forgetInstance(ServerInterface::class);
    }
}

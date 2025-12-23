<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Facades;

use Cline\Forrst\Contracts\ServerInterface;
use Cline\Forrst\Extensions\ExtensionRegistry;
use Cline\Forrst\Repositories\FunctionRepository;
use Illuminate\Support\Facades\Facade;
use Override;

/**
 * Server facade for accessing Forrst server configuration and metadata.
 *
 * Provides static access to the active Forrst server instance bound in Laravel's
 * service container. Use this facade within function implementations to access
 * server configuration, routing information, function repository, extension registry,
 * and Forrst Discovery metadata.
 *
 * The facade proxies method calls to the ServerInterface implementation, enabling
 * clean static access without manual container resolution. Commonly used to retrieve
 * function definitions, extension configurations, and server metadata.
 *
 * @method static ExtensionRegistry  getExtensionRegistry()  Get the extension registry for extension management
 * @method static FunctionRepository getFunctionRepository() Get the function repository for resolving functions
 * @method static array<int, string> getMiddleware()         Get HTTP middleware stack for this server
 * @method static string             getName()               Get the server name identifier
 * @method static string             getRouteName()          Get the Laravel route name for this server
 * @method static string             getRoutePath()          Get the HTTP path for this server endpoint
 * @method static string             getVersion()            Get the server version string
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see ServerInterface
 * @see https://docs.cline.sh/forrst/
 */
final class Server extends Facade
{
    /**
     * Get the service container binding key for the facade.
     *
     * Returns the interface name that resolves to the active ServerInterface
     * implementation in Laravel's service container.
     *
     * @return string The ServerInterface class name used as the container binding key
     */
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return ServerInterface::class;
    }
}

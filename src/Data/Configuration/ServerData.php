<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data\Configuration;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Data\AbstractData;
use Cline\Forrst\Exceptions\InvalidFieldTypeException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\MissingRequiredFieldException;
use InvalidArgumentException;

use function app;
use function base_path;
use function class_exists;
use function is_string;
use function preg_match;
use function realpath;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Configuration data for a single Forrst server instance.
 *
 * Represents the configuration for one Forrst server endpoint including its
 * routing information, version, middleware stack, and available functions.
 * Multiple server instances can be configured to provide different API
 * versions or isolated function sets.
 *
 * Each server acts as an independent RPC endpoint with its own route,
 * version, and function registry. This allows applications to run multiple
 * versions side-by-side or separate public and admin APIs.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 */
final class ServerData extends AbstractData
{
    /**
     * Create a new server configuration instance.
     *
     * @param string                                           $name       Unique identifier for this server instance, used
     *                                                                     to distinguish between multiple servers in the
     *                                                                     configuration (e.g., 'v1', 'admin', 'public').
     * @param string                                           $path       File system path or namespace where this server's
     *                                                                     function classes are located. Used for automatic
     *                                                                     function discovery during server initialization.
     * @param string                                           $route      HTTP route path where this server accepts requests.
     *                                                                     Should include leading slash and may include route
     *                                                                     parameters (e.g., '/api/v1/forrst', '/forrst/{version}').
     * @param string                                           $version    API version string for this server instance. Used
     *                                                                     for version tracking and documentation generation.
     *                                                                     Should follow semantic versioning (e.g., '1.0.0').
     * @param array<int, string>                               $middleware Ordered array of middleware class names or aliases
     *                                                                     that apply to all requests to this server. Executed
     *                                                                     in array order before function dispatching occurs.
     * @param null|array<int, class-string<FunctionInterface>> $functions  Optional array of function class names to register for
     *                                                                     this server. If null, all functions in the configured
     *                                                                     path will be auto-discovered and registered.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly string $route,
        public readonly string $version,
        public readonly array $middleware,
        public readonly ?array $functions,
    ) {
        $this->validatePath($this->path);
        $this->validateRoute($this->route);
        $this->validateMiddleware($this->middleware);
    }

    /**
     * Create a server configuration instance from an array.
     *
     * Factory method for creating ServerData instances from configuration arrays.
     * Validates required fields and provides sensible defaults for optional fields.
     *
     * @param array<string, mixed> $data Configuration data array
     *
     * @throws InvalidArgumentException If required fields are missing
     * @return self                     New ServerData instance
     */
    public static function createFromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? throw MissingRequiredFieldException::forField('name'),
            path: $data['path'] ?? throw MissingRequiredFieldException::forField('path'),
            route: $data['route'] ?? throw MissingRequiredFieldException::forField('route'),
            version: $data['version'] ?? '1.0.0',
            middleware: $data['middleware'] ?? [],
            functions: $data['functions'] ?? null,
        );
    }

    /**
     * Validate the server path to prevent directory traversal attacks.
     *
     * Ensures the path does not contain directory traversal sequences and
     * is either an absolute filesystem path or a valid namespace.
     *
     * @param string $path The path to validate
     *
     * @throws InvalidArgumentException If path contains traversal sequences or invalid format
     */
    private function validatePath(string $path): void
    {
        // Prevent directory traversal
        if (str_contains($path, '..')) {
            throw InvalidFieldValueException::forField(
                'path',
                sprintf('Path traversal detected in path: "%s"', $path),
            );
        }

        // Ensure absolute path or valid namespace
        if (!str_starts_with($path, '/') && !str_starts_with($path, 'App\\')) {
            throw InvalidFieldValueException::forField(
                'path',
                sprintf('Path must be absolute or valid namespace: "%s"', $path),
            );
        }

        // If filesystem path, verify it is within app (skip existence check for test flexibility)
        if (!str_starts_with($path, '/')) {
            return;
        }

        // Note: We don't check if the path exists to allow test fixtures and
        // configuration before directories are created. The directory traversal
        // check above provides the primary security protection.
        $realPath = realpath($path);

        // If path resolves (exists), verify it's within app root
        if ($realPath !== false) {
            $appPath = base_path();

            if (!str_starts_with($realPath, $appPath)) {
                throw InvalidFieldValueException::forField(
                    'path',
                    sprintf('Path is outside application root: "%s"', $path),
                );
            }
        }
    }

    /**
     * Validate the route format.
     *
     * Ensures the route starts with a forward slash and contains only
     * valid route characters (alphanumeric, hyphens, slashes, braces, asterisks).
     *
     * @param string $route The route to validate
     *
     * @throws InvalidArgumentException If route format is invalid
     */
    private function validateRoute(string $route): void
    {
        if (!str_starts_with($route, '/')) {
            throw InvalidFieldValueException::forField(
                'route',
                sprintf('Route "%s" must start with "/"', $route),
            );
        }

        if (!preg_match('#^/[\w\-/{}\*]+$#', $route)) {
            throw InvalidFieldValueException::forField(
                'route',
                sprintf('Invalid route format: "%s"', $route),
            );
        }
    }

    /**
     * Validate middleware array contains only valid class strings.
     *
     * Ensures all middleware entries are strings and optionally verifies
     * the classes exist in non-production environments.
     *
     * @param array<int, string> $middleware The middleware array to validate
     *
     * @throws InvalidArgumentException If middleware contains invalid entries or non-existent classes
     */
    private function validateMiddleware(array $middleware): void
    {
        foreach ($middleware as $index => $middlewareClass) {
            if (!is_string($middlewareClass)) {
                throw InvalidFieldTypeException::forField(
                    sprintf('middleware[%d]', $index),
                    'string',
                    $middlewareClass,
                );
            }

            // Validate class exists (in local/testing environments)
            if (app()->environment(['local', 'testing']) && !class_exists($middlewareClass)) {
                throw InvalidFieldValueException::forField(
                    'middleware',
                    sprintf('Middleware class "%s" does not exist', $middlewareClass),
                );
            }
        }
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst;

use Cline\Forrst\Contracts\ProtocolInterface;
use Cline\Forrst\Contracts\ResourceInterface;
use Cline\Forrst\Data\Configuration\ConfigurationData;
use Cline\Forrst\Extensions\ExtensionEventSubscriber;
use Cline\Forrst\Mixins\RouteMixin;
use Cline\Forrst\Repositories\ResourceRepository;
use Cline\Forrst\Servers\ConfigurationServer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Override;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Throwable;

use function assert;
use function class_exists;
use function config;
use function is_a;
use function is_string;

/**
 * Laravel service provider for the Forrst package.
 *
 * Handles package registration, configuration publishing, route registration,
 * resource discovery, and extension event subscription. Automatically configures
 * Forrst servers based on the published configuration file and registers the
 * custom Route mixin for convenient server registration.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 */
final class ServiceProvider extends PackageServiceProvider
{
    /**
     * Configure the package's publishable assets and install commands.
     *
     * Defines the package name, configuration files to publish, and the installation
     * command that publishes configuration and migration files to the Laravel application.
     *
     * @param Package $package Package configuration instance
     */
    #[Override()]
    public function configurePackage(Package $package): void
    {
        $package
            ->name('forrst')
            ->hasConfigFile('rpc')
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command->publishConfigFile();
                $command->publishMigrations();
            });
    }

    /**
     * Register package services in the Laravel container.
     *
     * Binds the ProtocolInterface as a singleton based on the rpc.protocol
     * configuration setting. Other core services are automatically registered
     * via their #[Singleton] attributes, reducing boilerplate registration code.
     *
     * @throws InvalidArgumentException If the configured protocol class is invalid
     */
    #[Override()]
    public function packageRegistered(): void
    {
        // ProtocolInterface requires runtime config resolution - cannot use attributes
        $this->app->singleton(ProtocolInterface::class, function (): ProtocolInterface {
            $protocolClass = config('rpc.protocol');

            if (!is_string($protocolClass)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Configuration rpc.protocol must be a class string, %s given',
                        gettype($protocolClass)
                    )
                );
            }

            if (!class_exists($protocolClass)) {
                throw new InvalidArgumentException(
                    sprintf('Protocol class %s does not exist', $protocolClass)
                );
            }

            try {
                $protocol = new $protocolClass();
            } catch (Throwable $e) {
                throw new InvalidArgumentException(
                    sprintf('Failed to instantiate protocol class %s: %s', $protocolClass, $e->getMessage()),
                    0,
                    $e
                );
            }

            if (!$protocol instanceof ProtocolInterface) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Protocol class %s must implement %s',
                        $protocolClass,
                        ProtocolInterface::class
                    )
                );
            }

            return $protocol;
        });
    }

    /**
     * Perform operations during package booting phase.
     *
     * Registers the custom Route mixin that adds the rpc() method to Laravel's
     * route facade, enabling convenient Forrst server registration in route files.
     * Also subscribes the ExtensionEventSubscriber to Laravel's event dispatcher
     * for extension lifecycle management.
     */
    #[Override()]
    public function bootingPackage(): void
    {
        Route::mixin(
            new RouteMixin(),
        );

        // Register extension event subscriber with Laravel's event dispatcher
        $events = $this->app->make(Dispatcher::class);
        $subscriber = $this->app->make(ExtensionEventSubscriber::class);

        $events->subscribe($subscriber);
    }

    /**
     * Boot package services after all providers are registered.
     *
     * Loads and validates the RPC configuration, registers resource mappings for
     * model-to-resource transformations, and creates ConfigurationServer instances
     * for each server defined in the configuration. Gracefully handles missing or
     * invalid configuration in console environments to prevent installation errors
     * before configuration is published.
     *
     * SECURITY: The rpc configuration file is trusted and should only be
     * modified by developers/administrators. Never populate configuration
     * from user input or untrusted sources.
     *
     * @throws Throwable Configuration validation errors in non-console environments
     */
    #[Override()]
    public function packageBooted(): void
    {
        try {
            $configuration = ConfigurationData::validateAndCreate((array) config('rpc'));

            // Validate and register resources
            foreach ($configuration->resources as $model => $resource) {
                if (!is_string($resource)) {
                    throw new InvalidArgumentException(
                        sprintf('Resource for model %s must be a class string, %s given', $model, gettype($resource))
                    );
                }

                if (!class_exists($resource)) {
                    throw new InvalidArgumentException(
                        sprintf('Resource class %s does not exist', $resource)
                    );
                }

                if (!is_a($resource, ResourceInterface::class, true)) {
                    throw new InvalidArgumentException(
                        sprintf('Resource class %s must implement %s', $resource, ResourceInterface::class)
                    );
                }

                assert(is_string($model));
                assert(class_exists($model));

                /** @var class-string $model */
                /** @var class-string<ResourceInterface> $resource */
                ResourceRepository::register($model, $resource);
            }

            foreach ($configuration->servers as $server) {
                $functionsPath = config('rpc.paths.functions');
                $functionsNamespace = config('rpc.namespaces.functions');

                if (!is_string($functionsPath)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Configuration rpc.paths.functions must be a string, %s given',
                            gettype($functionsPath)
                        )
                    );
                }

                if (!is_string($functionsNamespace)) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Configuration rpc.namespaces.functions must be a string, %s given',
                            gettype($functionsNamespace)
                        )
                    );
                }

                // @phpstan-ignore-next-line
                Route::rpc(
                    new ConfigurationServer(
                        $server,
                        $functionsPath,
                        $functionsNamespace,
                    ),
                );
            }
        } catch (Throwable $throwable) {
            // Only suppress if config file doesn't exist (during installation)
            if (App::runningInConsole() && !file_exists(config_path('rpc.php'))) {
                return;
            }

            throw $throwable;
        }
    }
}

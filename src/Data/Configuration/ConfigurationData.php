<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data\Configuration;

use Cline\Forrst\Data\AbstractData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\DataCollection;

/**
 * Main configuration data for the Forrst package.
 *
 * Holds the complete configuration structure including namespace mappings,
 * file paths, resource definitions, and server configurations. This data
 * object is populated from the rpc.php configuration file and used
 * throughout the application lifecycle.
 *
 * Serves as the central configuration container that defines how the Forrst
 * server discovers functions, where they are located in the filesystem,
 * and what endpoints are available. Multiple servers can be configured to
 * provide different API versions or isolated function sets.
 *
 * @see https://docs.cline.sh/forrst/
 */
final class ConfigurationData extends AbstractData
{
    /**
     * Create a new configuration data instance.
     *
     * @param array<string, string>           $namespaces Namespace configuration mappings that define
     *                                                    where RPC components are located. Maps namespace
     *                                                    prefixes to base namespaces for automatic class
     *                                                    discovery during server initialization (e.g.,
     *                                                    'functions' => 'App\\Rpc\\Functions').
     * @param array<string, string>           $paths      File system path mappings defining directory
     *                                                    locations for RPC components. Used for scanning
     *                                                    and discovering classes during server bootstrap
     *                                                    and function registration (e.g., 'functions' =>
     *                                                    app_path('Rpc/Functions')).
     * @param array<string, mixed>            $resources  Resource transformation configuration defining
     *                                                    how data models are converted to standardized
     *                                                    JSON representations. Currently unused but
     *                                                    reserved for future resource mapping features.
     * @param DataCollection<int, ServerData> $servers    Collection of server configuration objects
     *                                                    defining available RPC endpoints, their
     *                                                    routes, middleware stacks, and capabilities.
     *                                                    Each server represents a separate endpoint
     *                                                    with its own function set and configuration.
     */
    public function __construct(
        public readonly array $namespaces,
        public readonly array $paths,
        #[Present()]
        public readonly array $resources,
        #[DataCollectionOf(ServerData::class)]
        public readonly DataCollection $servers,
    ) {
        $this->validate();
    }

    /**
     * Create configuration from array data.
     *
     * @param array<string, mixed> $data Configuration array
     * @return self Configured instance
     */
    public static function createFromArray(array $data): self
    {
        return new self(
            namespaces: $data['namespaces'] ?? [],
            paths: $data['paths'] ?? [],
            resources: $data['resources'] ?? [],
            servers: DataCollection::create(
                ServerData::class,
                $data['servers'] ?? []
            ),
        );
    }

    /**
     * Create configuration from config file.
     *
     * @param string $configKey The config key (e.g., 'rpc')
     * @return self Configured instance
     */
    public static function createFromConfig(string $configKey = 'rpc'): self
    {
        $config = config($configKey, []);
        return self::createFromArray($config);
    }
}

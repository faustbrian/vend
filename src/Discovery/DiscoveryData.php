<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use Cline\Forrst\Discovery\Resource\ResourceData;
use Spatie\LaravelData\Data;

/**
 * Forrst Discovery Document.
 *
 * The root object describing a Forrst service for discovery purposes. This document
 * provides a complete machine-readable specification of the service's capabilities,
 * including available functions, server endpoints, resource schemas, and reusable
 * components. Used by clients to dynamically discover and interact with the service.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/specs/forrst/discovery#discovery-document-object
 */
final class DiscoveryData extends Data
{
    /**
     * Create a new Forrst discovery document instance.
     *
     * @param string                               $forrst       Forrst protocol version that the service implements (e.g., "1.0.0").
     *                                                           Indicates compatibility level with the Forrst specification and determines
     *                                                           which protocol features are available for client-server communication.
     * @param string                               $discovery    Discovery document format version (e.g., "1.0.0"). Defines the
     *                                                           structure and capabilities of this discovery document itself, allowing
     *                                                           clients to properly parse and interpret the document schema.
     * @param InfoData                             $info         Service metadata including title, version, description, and contact
     *                                                           information. Provides human-readable identification and documentation
     *                                                           for the service, displayed in API explorers and client tooling.
     * @param array<int, FunctionDescriptorData>   $functions    Available functions exposed by this service.
     *                                                           Each descriptor defines a callable operation
     *                                                           with its parameters, return type, possible
     *                                                           errors, and usage examples. Forms the core
     *                                                           API contract between client and server.
     * @param null|array<int, DiscoveryServerData> $servers      Server endpoint configurations defining where
     *                                                           the service can be accessed. Includes URL
     *                                                           templates with variable substitution for
     *                                                           multi-environment deployments and routing.
     * @param null|array<string, ResourceData>     $resources    Reusable resource type definitions keyed by
     *                                                           resource name. Defines the schema and structure
     *                                                           of domain objects returned by functions, enabling
     *                                                           schema validation and documentation generation.
     * @param null|ComponentsData                  $components   Reusable component definitions including schemas, parameters,
     *                                                           and response objects. Promotes schema reuse across multiple
     *                                                           functions through $ref references, reducing duplication and
     *                                                           maintaining consistency in the API specification.
     * @param null|ExternalDocsData                $externalDocs Reference to external documentation such as user guides,
     *                                                           tutorials, or comprehensive API documentation hosted
     *                                                           outside the discovery document. Provides context and
     *                                                           usage information beyond the technical specification.
     */
    public function __construct(
        public readonly string $forrst,
        public readonly string $discovery,
        public readonly InfoData $info,
        public readonly array $functions,
        public readonly ?array $servers = null,
        public readonly ?array $resources = null,
        public readonly ?ComponentsData $components = null,
        public readonly ?ExternalDocsData $externalDocs = null,
    ) {
        $this->validateVersions();
        $this->validateFunctions();
        $this->validateServers();
    }

    /**
     * Validate that functions array contains valid FunctionDescriptorData instances
     * and that function names are unique.
     *
     * @throws \InvalidArgumentException
     */
    private function validateFunctions(): void
    {
        if (empty($this->functions)) {
            throw new \InvalidArgumentException('Discovery document must define at least one function');
        }

        foreach ($this->functions as $index => $function) {
            if (!$function instanceof FunctionDescriptorData) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'All functions must be instances of FunctionDescriptorData, got %s at index %d',
                        get_debug_type($function),
                        $index
                    )
                );
            }
        }

        // Validate function name uniqueness
        $names = array_map(fn ($f) => $f->name, $this->functions);
        $duplicates = array_filter(array_count_values($names), fn ($count) => $count > 1);

        if (!empty($duplicates)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Duplicate function names found: %s',
                    implode(', ', array_keys($duplicates))
                )
            );
        }
    }

    /**
     * Validate that servers array contains valid DiscoveryServerData instances.
     *
     * @throws \InvalidArgumentException
     */
    private function validateServers(): void
    {
        if ($this->servers === null) {
            return;
        }

        foreach ($this->servers as $index => $server) {
            if (!$server instanceof DiscoveryServerData) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'All servers must be instances of DiscoveryServerData, got %s at index %d',
                        get_debug_type($server),
                        $index
                    )
                );
            }
        }
    }

    /**
     * Validate semantic versioning for forrst and discovery fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validateVersions(): void
    {
        $this->validateSemanticVersion($this->forrst, 'Forrst protocol version');
        $this->validateSemanticVersion($this->discovery, 'Discovery document version');
    }

    /**
     * Validate that a version string follows semantic versioning format.
     *
     * @param string $version   The version string to validate
     * @param string $fieldName The field name for error messages
     *
     * @throws \InvalidArgumentException
     */
    private function validateSemanticVersion(string $version, string $fieldName): void
    {
        // Semantic versioning: MAJOR.MINOR.PATCH with optional pre-release and build metadata
        $pattern = '/^\d+\.\d+\.\d+(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?(\+[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/';

        if (!preg_match($pattern, $version)) {
            throw new \InvalidArgumentException(
                sprintf(
                    '%s "%s" must follow semantic versioning (e.g., "1.0.0", "2.1.0-beta.1")',
                    $fieldName,
                    $version
                )
            );
        }
    }
}

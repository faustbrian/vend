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
 * Reusable component definitions for API discovery documentation.
 *
 * Provides a centralized registry of reusable schemas, content descriptors, error definitions,
 * examples, example pairings, links, tags, and resources that can be referenced throughout
 * the discovery document using $ref notation. This promotes consistency and reduces duplication
 * in API documentation.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/discovery#components
 */
final class ComponentsData extends Data
{
    /**
     * Create a new components definition instance.
     *
     * @param null|array<string, array<string, mixed>> $schemas            Reusable JSON Schema definitions indexed by name.
     *                                                                     These schemas can be referenced using $ref notation
     *                                                                     throughout the discovery document to maintain consistency
     *                                                                     and reduce duplication of complex type definitions.
     * @param null|array<string, ArgumentData>         $contentDescriptors Reusable content descriptor definitions indexed by name.
     *                                                                     Commonly used parameters (pagination, filters, authentication)
     *                                                                     can be defined once and referenced across multiple function
     *                                                                     arguments using $ref notation.
     * @param null|array<string, ErrorDefinitionData>  $errors             Reusable error definitions indexed by error code. Defines
     *                                                                     standard error structures, status codes, and messages that
     *                                                                     functions can return, ensuring consistent error handling.
     * @param null|array<string, ExampleData>          $examples           Reusable example value definitions indexed by name. Standalone
     *                                                                     example values that can be referenced from content descriptors
     *                                                                     or result schemas to illustrate expected data formats.
     * @param null|array<string, ExamplePairingData>   $examplePairings    Reusable request-response pair definitions indexed by name.
     *                                                                     Complete function invocation examples showing arguments and
     *                                                                     expected results together, demonstrating end-to-end usage.
     * @param null|array<string, LinkData>             $links              Reusable link definitions indexed by name. Describe relationships
     *                                                                     between functions and enable clients to discover related
     *                                                                     operations and navigation paths through the API.
     * @param null|array<string, TagData>              $tags               Reusable tag definitions indexed by name. Tags provide
     *                                                                     metadata for categorizing and organizing functions within
     *                                                                     the API documentation interface.
     * @param null|array<string, ResourceData>         $resources          Reusable resource definitions indexed by type name. Defines
     *                                                                     the structure and transformation rules for domain entities
     *                                                                     returned by API functions.
     */
    public function __construct(
        public readonly ?array $schemas = null,
        public readonly ?array $contentDescriptors = null,
        public readonly ?array $errors = null,
        public readonly ?array $examples = null,
        public readonly ?array $examplePairings = null,
        public readonly ?array $links = null,
        public readonly ?array $tags = null,
        public readonly ?array $resources = null,
    ) {}

    /**
     * Validate that a component reference exists.
     *
     * @param string $ref Component reference (e.g., "#/components/schemas/User")
     *
     * @return bool True if the reference exists, false otherwise
     */
    public function hasReference(string $ref): bool
    {
        if (!\str_starts_with($ref, '#/components/')) {
            return false;
        }

        $parts = \explode('/', \substr($ref, 13)); // Remove '#/components/'

        if (\count($parts) !== 2) {
            return false;
        }

        [$componentType, $componentName] = $parts;

        return match ($componentType) {
            'schemas' => isset($this->schemas[$componentName]),
            'contentDescriptors' => isset($this->contentDescriptors[$componentName]),
            'errors' => isset($this->errors[$componentName]),
            'examples' => isset($this->examples[$componentName]),
            'examplePairings' => isset($this->examplePairings[$componentName]),
            'links' => isset($this->links[$componentName]),
            'tags' => isset($this->tags[$componentName]),
            'resources' => isset($this->resources[$componentName]),
            default => false,
        };
    }

    /**
     * Resolve a component reference to its actual data.
     *
     * @param string $ref Component reference (e.g., "#/components/schemas/User")
     *
     * @return mixed The resolved component data
     *
     * @throws \InvalidArgumentException If reference doesn't exist
     */
    public function resolveReference(string $ref): mixed
    {
        if (!$this->hasReference($ref)) {
            throw new \InvalidArgumentException("Component reference '{$ref}' does not exist");
        }

        $parts = \explode('/', \substr($ref, 13));
        [$componentType, $componentName] = $parts;

        return match ($componentType) {
            'schemas' => $this->schemas[$componentName],
            'contentDescriptors' => $this->contentDescriptors[$componentName],
            'errors' => $this->errors[$componentName],
            'examples' => $this->examples[$componentName],
            'examplePairings' => $this->examplePairings[$componentName],
            'links' => $this->links[$componentName],
            'tags' => $this->tags[$componentName],
            'resources' => $this->resources[$componentName],
        };
    }
}

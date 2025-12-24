<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use Cline\Forrst\Discovery\Query\QueryCapabilitiesData;
use Cline\Forrst\Exceptions\EmptyFieldException;
use Cline\Forrst\Exceptions\InvalidFieldTypeException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\InvalidFunctionNameException;
use Spatie\LaravelData\Data;

use function implode;
use function in_array;
use function is_array;
use function mb_trim;
use function preg_match;
use function sprintf;

/**
 * Function descriptor for discovery documents.
 *
 * Describes a callable function with its name, version, arguments, result, and errors.
 * Forms the core contract between client and server by defining the complete signature
 * and behavior of a remote function call. Includes metadata for versioning, deprecation,
 * error handling, and extension support.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/specs/forrst/discovery#function-object
 */
final class FunctionDescriptorData extends Data
{
    /**
     * Create a new function descriptor.
     *
     * @param string                                            $name         Unique function identifier in dot-separated namespace format
     *                                                                        (e.g., "users.get", "payments.create"). Used by clients to invoke
     *                                                                        the function via RPC calls and serves as the routing key for
     *                                                                        server-side method resolution.
     * @param string                                            $version      Semantic version number (e.g., "1.2.0") indicating the function's
     *                                                                        API version. Allows multiple versions of the same function to coexist
     *                                                                        for backward compatibility and gradual migration paths.
     * @param array<int, ArgumentData>                          $arguments    Ordered list of function parameters defining accepted
     *                                                                        inputs. Each argument specifies name, type, validation
     *                                                                        rules, and whether it's required or optional. Enables
     *                                                                        client-side validation before making RPC calls.
     * @param null|string                                       $stability    API stability level derived from version prerelease identifier
     *                                                                        (e.g., "stable", "beta", "alpha"). Indicates the maturity and
     *                                                                        expected change frequency of the function's interface. Helps
     *                                                                        clients make informed decisions about adoption timing.
     * @param null|string                                       $summary      Brief one-line description of the function's purpose and behavior.
     *                                                                        Displayed in API explorers, autocomplete suggestions, and function
     *                                                                        lists to help developers quickly understand what the function does.
     * @param null|string                                       $description  Comprehensive explanation of the function's behavior, use cases,
     *                                                                        and important notes. Provides detailed context for documentation
     *                                                                        generation and helps developers understand when and how to use
     *                                                                        the function effectively.
     * @param null|array<int, array<mixed>|TagData>             $tags         Logical grouping labels for organizing functions into
     *                                                                        categories (e.g., "users", "payments", "admin"). Used
     *                                                                        by API explorers and documentation generators to create
     *                                                                        navigable function hierarchies.
     * @param null|ResultDescriptorData                         $result       Definition of the function's return value including type,
     *                                                                        schema, and description. Specifies what successful execution
     *                                                                        returns to the client, enabling type checking and response
     *                                                                        validation in client implementations.
     * @param null|array<int, array<mixed>|ErrorDefinitionData> $errors       Documented error conditions this function
     *                                                                        may return. Each definition includes error
     *                                                                        code, message template, and optional schema
     *                                                                        for error details, enabling comprehensive
     *                                                                        error handling in client code.
     * @param null|QueryCapabilitiesData                        $query        Query extension capabilities for functions returning collections.
     *                                                                        Defines support for filtering, sorting, pagination, field selection,
     *                                                                        and other query operations per the Forrst Query Extension specification.
     * @param null|DeprecatedData                               $deprecated   Deprecation information if this function version is obsolete.
     *                                                                        Contains sunset date, migration instructions, and replacement
     *                                                                        function references to guide clients through API transitions.
     * @param null|array<int, string>                           $sideEffects  Declared side effects this function performs using standard
     *                                                                        values: "create", "update", "delete". Indicates whether the
     *                                                                        function is idempotent and what state changes it makes, helping
     *                                                                        clients implement appropriate retry and caching strategies.
     * @param bool                                              $discoverable Whether this function appears in discovery documents. Defaults to true.
     *                                                                        Setting to false hides internal or deprecated functions from standard
     *                                                                        client discovery while keeping them available for backward compatibility
     *                                                                        or internal use.
     * @param null|array<int, ExampleData>                      $examples     Concrete usage examples demonstrating function calls with
     *                                                                        realistic arguments and expected responses. Each example
     *                                                                        shows a different use case or scenario, helping developers
     *                                                                        understand the function through practical demonstrations.
     * @param null|array<int, SimulationScenarioData>           $simulations  Predefined input/output pairs for sandbox and demo modes.
     *                                                                        Unlike examples (documentation), simulations are executable
     *                                                                        and allow clients to invoke functions in a sandboxed environment
     *                                                                        without affecting real data or triggering actual side effects.
     * @param null|array<int, array<mixed>|LinkData>            $links        Related functions and navigation links. Describe relationships
     *                                                                        between this function and other operations, enabling clients
     *                                                                        to discover and navigate to related functionality.
     * @param null|ExternalDocsData                             $externalDocs Reference to additional external documentation for this function.
     *                                                                        Links to tutorials, guides, or detailed API documentation
     *                                                                        providing context beyond the technical specification.
     * @param null|FunctionExtensionsData                       $extensions   Per-function extension support configuration. Overrides
     *                                                                        server-wide extension settings by defining which protocol
     *                                                                        extensions this specific function supports or excludes,
     *                                                                        enabling fine-grained control over extension availability.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly array $arguments,
        public readonly ?string $stability = null,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        /** @var null|array<int, array<mixed>|TagData> */
        public readonly ?array $tags = null,
        public readonly ?ResultDescriptorData $result = null,
        /** @var null|array<int, array<mixed>|ErrorDefinitionData> */
        public readonly ?array $errors = null,
        public readonly ?QueryCapabilitiesData $query = null,
        public readonly ?DeprecatedData $deprecated = null,
        public readonly ?array $sideEffects = null,
        public readonly bool $discoverable = true,
        public readonly ?array $examples = null,
        public readonly ?array $simulations = null,
        /** @var null|array<int, array<mixed>|LinkData> */
        public readonly ?array $links = null,
        public readonly ?ExternalDocsData $externalDocs = null,
        public readonly ?FunctionExtensionsData $extensions = null,
    ) {
        // Validate name (URN format)
        if (mb_trim($name) === '') {
            throw EmptyFieldException::forField('name');
        }

        if (!preg_match('/^urn:[a-z][a-z0-9-]*:forrst:(?:ext:[a-z][a-z0-9-]*:)?fn:[a-z][a-z0-9:.-]*$/i', $name)) {
            throw InvalidFunctionNameException::forName(
                $name,
                "Expected format: 'urn:namespace:forrst:ext:extension:fn:name' or 'urn:namespace:forrst:fn:name'",
            );
        }

        // Validate semantic version
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'.
            '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)'.
            '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'.
            '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $version)) {
            throw InvalidFieldValueException::forField(
                'version',
                sprintf("Invalid semantic version: '%s'. Must follow semver format (e.g., '1.0.0')", $version),
            );
        }

        // Validate arguments array type
        foreach ($arguments as $index => $argument) {
            if (!$argument instanceof ArgumentData) {
                throw InvalidFieldTypeException::forField(
                    sprintf('arguments[%d]', $index),
                    ArgumentData::class,
                    $argument,
                );
            }
        }

        // Validate mutually exclusive fields
        $this->validateArrayTypes();
    }

    /**
     * Validate array field types.
     */
    private function validateArrayTypes(): void
    {
        // tags arrays must contain proper types
        if ($this->tags !== null) {
            foreach ($this->tags as $index => $tag) {
                if (!is_array($tag) && !$tag instanceof TagData) {
                    throw InvalidFieldTypeException::forField(
                        sprintf('tags[%d]', $index),
                        'array or '.TagData::class,
                        $tag,
                    );
                }
            }
        }

        // errors arrays must contain proper types
        if ($this->errors !== null) {
            foreach ($this->errors as $index => $error) {
                if (!is_array($error) && !$error instanceof ErrorDefinitionData) {
                    throw InvalidFieldTypeException::forField(
                        sprintf('errors[%d]', $index),
                        'array or '.ErrorDefinitionData::class,
                        $error,
                    );
                }
            }
        }

        // links arrays must contain proper types
        if ($this->links !== null) {
            foreach ($this->links as $index => $link) {
                if (!is_array($link) && !$link instanceof LinkData) {
                    throw InvalidFieldTypeException::forField(
                        sprintf('links[%d]', $index),
                        'array or '.LinkData::class,
                        $link,
                    );
                }
            }
        }

        // sideEffects must use standard values
        if ($this->sideEffects === null) {
            return;
        }

        $validEffects = ['create', 'update', 'delete', 'read'];

        foreach ($this->sideEffects as $effect) {
            if (!in_array($effect, $validEffects, true)) {
                throw InvalidFieldValueException::forField(
                    'sideEffects',
                    sprintf("Invalid value: '%s'. Must be one of: ", $effect).implode(', ', $validEffects),
                );
            }
        }
    }
}

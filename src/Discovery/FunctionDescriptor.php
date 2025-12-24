<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use BackedEnum;
use Cline\Forrst\Discovery\Query\QueryCapabilitiesData;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\InvalidInputSchemaException;
use Cline\Forrst\Exceptions\MissingRequiredFieldException;
use Cline\Forrst\Exceptions\UnknownSchemaTypeException;
use InvalidArgumentException;

use function in_array;
use function preg_match;
use function sprintf;

/**
 * Fluent builder for function discovery descriptors.
 *
 * Separates discovery metadata from function implementation, providing
 * a clean API for defining function schemas, arguments, and results.
 * Used by descriptor classes implementing DescriptorInterface.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/discovery
 */
final class FunctionDescriptor
{
    private string $urn;

    private string $version = '1.0.0';

    private string $summary;

    private ?string $description = null;

    private bool $discoverable = true;

    /** @var array<int, ArgumentData> */
    private array $arguments = [];

    private ?ResultDescriptorData $result = null;

    /** @var array<int, ErrorDefinitionData> */
    private array $errors = [];

    /** @var null|array<int, TagData> */
    private ?array $tags = null;

    private ?QueryCapabilitiesData $query = null;

    private ?DeprecatedData $deprecated = null;

    /** @var null|array<int, string> */
    private ?array $sideEffects = null;

    /** @var null|array<int, ExampleData> */
    private ?array $examples = null;

    /** @var null|array<int, LinkData> */
    private ?array $links = null;

    private ?ExternalDocsData $externalDocs = null;

    /** @var null|array<string, mixed> */
    private ?array $security = null;

    /** @var null|array<int, SimulationScenarioData> */
    private ?array $simulations = null;

    private ?FunctionExtensionsData $extensions = null;

    private function __construct() {}

    /**
     * Create a new function descriptor builder.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Set the function URN.
     *
     * @param BackedEnum|string $urn Function URN or URN enum (e.g., "urn:acme:forrst:fn:orders:create")
     *
     * @throws InvalidArgumentException If URN format invalid
     */
    public function urn(BackedEnum|string $urn): self
    {
        $urnString = $urn instanceof BackedEnum ? (string) $urn->value : $urn;

        // Validate URN format: urn:namespace:forrst:ext:extension:fn:name or urn:namespace:forrst:fn:name
        if (!preg_match('/^urn:[a-z][a-z0-9-]*:forrst:(?:ext:[a-z][a-z0-9-]*:)?fn:[a-z][a-z0-9:.-]*$/i', $urnString)) {
            throw InvalidFieldValueException::forField(
                'urn',
                sprintf("Invalid format: '%s'. Expected format: 'urn:namespace:forrst:ext:extension:fn:name' or 'urn:namespace:forrst:fn:name'", $urnString),
            );
        }

        $this->urn = $urnString;

        return $this;
    }

    /**
     * Set the function version.
     *
     * @param string $version Semantic version (e.g., "1.0.0", "2.0.0-beta.1")
     *
     * @throws InvalidArgumentException If version invalid
     */
    public function version(string $version): self
    {
        // Validate semantic versioning format
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'.
            '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)'.
            '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'.
            '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $version)) {
            throw InvalidFieldValueException::forField(
                'version',
                sprintf("Invalid semantic version: '%s'. Must follow semver format (e.g., '1.0.0', '2.1.0-beta.1', '3.0.0+build.123'). See: https://semver.org/", $version),
            );
        }

        $this->version = $version;

        return $this;
    }

    /**
     * Set a brief summary of the function's purpose.
     *
     * @param string $summary One-line description
     */
    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * Set a detailed description of the function.
     *
     * @param string $description Extended description (supports Markdown)
     */
    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Mark the function as hidden from discovery.
     */
    public function hidden(): self
    {
        $this->discoverable = false;

        return $this;
    }

    /**
     * Add an argument to the function.
     *
     * @param string                 $name        Argument name
     * @param array<string, mixed>   $schema      JSON Schema definition
     * @param bool                   $required    Whether the argument is required
     * @param null|string            $summary     Brief description
     * @param null|string            $description Detailed description
     * @param mixed                  $default     Default value if not required
     * @param null|DeprecatedData    $deprecated  Deprecation info
     * @param null|array<int, mixed> $examples    Example values
     *
     * @throws InvalidArgumentException If schema is invalid
     */
    public function argument(
        string $name,
        array $schema = ['type' => 'string'],
        bool $required = false,
        ?string $summary = null,
        ?string $description = null,
        mixed $default = null,
        ?DeprecatedData $deprecated = null,
        ?array $examples = null,
    ): self {
        // Validate schema structure
        $this->validateJsonSchema($schema);

        $this->arguments[] = new ArgumentData(
            name: $name,
            schema: $schema,
            required: $required,
            summary: $summary,
            description: $description,
            default: $default,
            deprecated: $deprecated,
            examples: $examples,
        );

        return $this;
    }

    /**
     * Add a pre-built ArgumentData to the function.
     *
     * @param ArgumentData $argument Argument descriptor
     */
    public function addArgument(ArgumentData $argument): self
    {
        $this->arguments[] = $argument;

        return $this;
    }

    /**
     * Set the result schema using a resource type.
     *
     * @param string      $resource    Resource type name
     * @param bool        $collection  Whether it returns a collection
     * @param null|string $description Description of the result
     */
    public function resultResource(
        string $resource,
        bool $collection = false,
        ?string $description = null,
    ): self {
        $this->result = new ResultDescriptorData(
            resource: $resource,
            collection: $collection,
            description: $description,
        );

        return $this;
    }

    /**
     * Set the result schema using a JSON Schema.
     *
     * @param array<string, mixed> $schema      JSON Schema definition
     * @param bool                 $collection  Whether it returns a collection
     * @param null|string          $description Description of the result
     */
    public function result(
        array $schema,
        bool $collection = false,
        ?string $description = null,
    ): self {
        $this->result = new ResultDescriptorData(
            schema: $schema,
            collection: $collection,
            description: $description,
        );

        return $this;
    }

    /**
     * Set a pre-built ResultDescriptorData.
     *
     * @param ResultDescriptorData $result Result descriptor
     */
    public function setResult(ResultDescriptorData $result): self
    {
        $this->result = $result;

        return $this;
    }

    /**
     * Add an error definition.
     *
     * @param BackedEnum|string         $code        Error code
     * @param string                    $message     Human-readable message
     * @param null|string               $description Detailed description
     * @param null|array<string, mixed> $details     JSON Schema for error details
     */
    public function error(
        BackedEnum|string $code,
        string $message,
        ?string $description = null,
        ?array $details = null,
    ): self {
        $this->errors[] = new ErrorDefinitionData(
            code: $code,
            message: $message,
            description: $description,
            details: $details,
        );

        return $this;
    }

    /**
     * Add a pre-built ErrorDefinitionData.
     *
     * @param ErrorDefinitionData $error Error definition
     */
    public function addError(ErrorDefinitionData $error): self
    {
        $this->errors[] = $error;

        return $this;
    }

    /**
     * Add a tag for grouping.
     *
     * @param BackedEnum|string     $name         Tag name
     * @param null|string           $summary      Brief description
     * @param null|string           $description  Detailed description
     * @param null|ExternalDocsData $externalDocs External documentation reference
     */
    public function tag(
        BackedEnum|string $name,
        ?string $summary = null,
        ?string $description = null,
        ?ExternalDocsData $externalDocs = null,
    ): self {
        $this->tags ??= [];
        $this->tags[] = new TagData(
            name: $name instanceof BackedEnum ? (string) $name->value : $name,
            summary: $summary,
            description: $description,
            externalDocs: $externalDocs,
        );

        return $this;
    }

    /**
     * Set query capabilities for list functions.
     *
     * @param QueryCapabilitiesData $query Query capabilities
     */
    public function query(QueryCapabilitiesData $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Mark the function as deprecated.
     *
     * @param null|string $reason Reason for deprecation
     * @param null|string $sunset ISO 8601 date when function will be removed
     */
    public function deprecated(?string $reason = null, ?string $sunset = null): self
    {
        $this->deprecated = new DeprecatedData(reason: $reason, sunset: $sunset);

        return $this;
    }

    /**
     * Set side effects this function may cause.
     *
     * @param array<int, string> $effects Side effect types (e.g., 'create', 'update', 'delete')
     */
    public function sideEffects(array $effects): self
    {
        $this->sideEffects = $effects;

        return $this;
    }

    /**
     * Add an example.
     *
     * @param ExampleData $example Example data
     */
    public function example(ExampleData $example): self
    {
        $this->examples ??= [];
        $this->examples[] = $example;

        return $this;
    }

    /**
     * Add a link to a related function.
     *
     * @param LinkData $link Link data
     */
    public function link(LinkData $link): self
    {
        $this->links ??= [];
        $this->links[] = $link;

        return $this;
    }

    /**
     * Set external documentation reference.
     *
     * @param string      $url         URL to external documentation
     * @param null|string $description Description of the documentation
     */
    public function externalDocs(string $url, ?string $description = null): self
    {
        $this->externalDocs = new ExternalDocsData(url: $url, description: $description);

        return $this;
    }

    /**
     * Add a simulation scenario.
     *
     * @param SimulationScenarioData $simulation Simulation scenario
     */
    public function simulation(SimulationScenarioData $simulation): self
    {
        $this->simulations ??= [];
        $this->simulations[] = $simulation;

        return $this;
    }

    /**
     * Set per-function extension configuration.
     *
     * @param FunctionExtensionsData $extensions Extension configuration
     */
    public function extensions(FunctionExtensionsData $extensions): self
    {
        $this->extensions = $extensions;

        return $this;
    }

    /**
     * Set security/authorization metadata.
     *
     * @param array<string, mixed> $security Security configuration (authentication, authorization, scope)
     */
    public function security(array $security): self
    {
        $this->security = $security;

        return $this;
    }

    // -------------------------------------------------------------------------
    // Getters for building discovery documents
    // -------------------------------------------------------------------------

    public function getUrn(): string
    {
        if (!isset($this->urn)) {
            throw MissingRequiredFieldException::forField('urn');
        }

        return $this->urn;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getSummary(): string
    {
        if (!isset($this->summary)) {
            throw MissingRequiredFieldException::forField('summary');
        }

        return $this->summary;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isDiscoverable(): bool
    {
        return $this->discoverable;
    }

    /**
     * @return array<int, ArgumentData>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getResult(): ?ResultDescriptorData
    {
        return $this->result;
    }

    /**
     * @return array<int, ErrorDefinitionData>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return null|array<int, TagData>
     */
    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function getQuery(): ?QueryCapabilitiesData
    {
        return $this->query;
    }

    public function getDeprecated(): ?DeprecatedData
    {
        return $this->deprecated;
    }

    /**
     * @return null|array<int, string>
     */
    public function getSideEffects(): ?array
    {
        return $this->sideEffects;
    }

    /**
     * @return null|array<int, ExampleData>
     */
    public function getExamples(): ?array
    {
        return $this->examples;
    }

    /**
     * @return null|array<int, LinkData>
     */
    public function getLinks(): ?array
    {
        return $this->links;
    }

    public function getExternalDocs(): ?ExternalDocsData
    {
        return $this->externalDocs;
    }

    /**
     * @return null|array<int, SimulationScenarioData>
     */
    public function getSimulations(): ?array
    {
        return $this->simulations;
    }

    public function getExtensions(): ?FunctionExtensionsData
    {
        return $this->extensions;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function getSecurity(): ?array
    {
        return $this->security;
    }

    /**
     * Validate JSON Schema structure.
     *
     * @param array<string, mixed> $schema
     *
     * @throws InvalidArgumentException
     */
    private function validateJsonSchema(array $schema): void
    {
        if (!isset($schema['type']) && !isset($schema['$ref'])) {
            throw InvalidInputSchemaException::forField(
                'schema',
                'JSON Schema must include "type" or "$ref" property',
            );
        }

        if (!isset($schema['type'])) {
            return;
        }

        $validTypes = ['null', 'boolean', 'object', 'array', 'number', 'string', 'integer'];

        if (!in_array($schema['type'], $validTypes, true)) {
            throw UnknownSchemaTypeException::forType($schema['type']);
        }
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Functions;

use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Contracts\DescriptorInterface;
use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\DeprecatedData;
use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Discovery\ExampleData;
use Cline\Forrst\Discovery\ExternalDocsData;
use Cline\Forrst\Discovery\FunctionDescriptor;
use Cline\Forrst\Discovery\FunctionExtensionsData;
use Cline\Forrst\Discovery\LinkData;
use Cline\Forrst\Discovery\Query\QueryCapabilitiesData;
use Cline\Forrst\Discovery\ResultDescriptorData;
use Cline\Forrst\Discovery\SimulationScenarioData;
use Cline\Forrst\Discovery\TagData;
use Cline\Forrst\Functions\Concerns\InteractsWithAuthentication;
use Cline\Forrst\Functions\Concerns\InteractsWithCancellation;
use Cline\Forrst\Functions\Concerns\InteractsWithQueryBuilder;
use Cline\Forrst\Functions\Concerns\InteractsWithTransformer;
use Illuminate\Support\Str;
use Override;
use ReflectionClass;

use function class_basename;

/**
 * Base class for all Forrst function implementations.
 *
 * Provides comprehensive foundation for building Forrst functions with authentication
 * helpers, query building, data transformation, cancellation checking, and Forrst
 * Discovery metadata generation. Implements FunctionInterface with sensible defaults
 * that streamline function development while allowing full customization.
 *
 * Functions extend this class and implement a handle() or __invoke() method to define
 * their business logic. Discovery metadata can be provided via the #[Descriptor] attribute
 * pointing to a dedicated descriptor class, or by overriding the getter methods directly.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/forrst/protocol
 */
abstract class AbstractFunction implements FunctionInterface
{
    use InteractsWithAuthentication;
    use InteractsWithCancellation;
    use InteractsWithQueryBuilder;
    use InteractsWithTransformer;

    /**
     * The current Forrst request object containing arguments and metadata.
     *
     * Set by the framework before function execution via setRequest(). Access
     * this property to retrieve request arguments, extension options, and other
     * request metadata within your function's handle() method.
     */
    protected RequestObjectData $requestObject;

    /**
     * Cached function descriptor resolved from #[Descriptor] attribute.
     */
    private ?FunctionDescriptor $descriptor = null;

    /**
     * Whether we've attempted to resolve the descriptor.
     */
    private bool $descriptorResolved = false;

    /**
     * Delegate to descriptor or return default value.
     *
     * @template T
     *
     * @param  callable(FunctionDescriptor): T  $getter
     * @param  T  $default
     * @return T
     */
    private function fromDescriptorOr(callable $getter, mixed $default): mixed
    {
        $descriptor = $this->resolveDescriptor();

        return $descriptor instanceof FunctionDescriptor
            ? $getter($descriptor)
            : $default;
    }

    /**
     * Get the URN (Uniform Resource Name) for this function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise generates
     * the URN using the configured vendor and class name in kebab-case.
     *
     * @return string The Forrst function URN (e.g., 'urn:acme:forrst:fn:users:list')
     */
    #[Override()]
    public function getUrn(): string
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getUrn(),
            function (): string {
                /** @var string $vendor */
                $vendor = config('rpc.vendor', 'app');
                $name = Str::kebab(class_basename(static::class));
                $name = (string) preg_replace('/-function$/', '', $name);

                return "urn:{$vendor}:forrst:fn:{$name}";
            },
        );
    }

    /**
     * Get the function version.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns "1.0.0".
     *
     * @return string The function version (e.g., "1.0.0", "2.0.0-beta.1")
     */
    #[Override()]
    public function getVersion(): string
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getVersion(),
            '1.0.0',
        );
    }

    /**
     * Get the function summary for discovery documentation.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns the function URN.
     *
     * @return string A brief summary of the function's purpose
     */
    #[Override()]
    public function getSummary(): string
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getSummary(),
            fn (): string => $this->getUrn(),
        );
    }

    /**
     * Get the argument descriptors for the function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns an empty array.
     *
     * @return list<ArgumentData> Array of argument descriptors
     */
    #[Override()]
    public function getArguments(): array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getArguments(),
            [],
        );
    }

    /**
     * Get the result descriptor for the function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|ResultDescriptorData The result descriptor, or null if none specified
     */
    #[Override()]
    public function getResult(): ?ResultDescriptorData
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getResult(),
            null,
        );
    }

    /**
     * Get the error definitions for the function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns an empty array.
     *
     * @return list<ErrorDefinitionData> Array of error definitions
     */
    #[Override()]
    public function getErrors(): array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getErrors(),
            [],
        );
    }

    /**
     * Get a detailed description of the function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|string Detailed description or null
     */
    #[Override()]
    public function getDescription(): ?string
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getDescription(),
            null,
        );
    }

    /**
     * Get tags for logical grouping of functions.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|list<TagData> Array of tags or null
     */
    #[Override()]
    public function getTags(): ?array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getTags(),
            null,
        );
    }

    /**
     * Get query capabilities for list functions.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|QueryCapabilitiesData Query capabilities or null
     */
    #[Override()]
    public function getQuery(): ?QueryCapabilitiesData
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getQuery(),
            null,
        );
    }

    /**
     * Get deprecation information if the function is deprecated.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|DeprecatedData Deprecation info or null
     */
    #[Override()]
    public function getDeprecated(): ?DeprecatedData
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getDeprecated(),
            null,
        );
    }

    /**
     * Get side effects this function may cause.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null (read-only).
     *
     * @return null|list<string> Side effects or null
     */
    #[Override()]
    public function getSideEffects(): ?array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getSideEffects(),
            null,
        );
    }

    /**
     * Check if the function should appear in discovery responses.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns true.
     *
     * @return bool True if discoverable
     */
    #[Override()]
    public function isDiscoverable(): bool
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->isDiscoverable(),
            true,
        );
    }

    /**
     * Get usage examples for the function.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|list<ExampleData> Examples or null
     */
    #[Override()]
    public function getExamples(): ?array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getExamples(),
            null,
        );
    }

    /**
     * Get related function links for navigation.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|list<LinkData> Links or null
     */
    #[Override()]
    public function getLinks(): ?array
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getLinks(),
            null,
        );
    }

    /**
     * Get external documentation reference.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|ExternalDocsData External docs or null
     */
    #[Override()]
    public function getExternalDocs(): ?ExternalDocsData
    {
        return $this->fromDescriptorOr(
            fn (FunctionDescriptor $d) => $d->getExternalDocs(),
            null,
        );
    }

    /**
     * Get simulation scenarios for sandbox/demo mode.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|array<int, array<string, mixed>|SimulationScenarioData> Scenarios or null
     */
    #[Override()]
    public function getSimulations(): ?array
    {
        if (($descriptor = $this->resolveDescriptor()) instanceof FunctionDescriptor) {
            return $descriptor->getSimulations();
        }

        return null;
    }

    /**
     * Get per-function extension support configuration.
     *
     * Reads from the #[Descriptor] attribute if present, otherwise returns null.
     *
     * @return null|FunctionExtensionsData Extension support config or null
     */
    #[Override()]
    public function getExtensions(): ?FunctionExtensionsData
    {
        if (($descriptor = $this->resolveDescriptor()) instanceof FunctionDescriptor) {
            return $descriptor->getExtensions();
        }

        return null;
    }

    /**
     * Set the current request object for the function.
     *
     * Called before function execution to provide access to request arguments
     * and metadata throughout the function's lifecycle.
     *
     * @param RequestObjectData $requestObject The Forrst request data
     */
    #[Override()]
    public function setRequest(RequestObjectData $requestObject): void
    {
        $this->requestObject = $requestObject;
    }

    /**
     * Resolve the function descriptor from the #[Descriptor] attribute.
     *
     * Caches the result to avoid repeated reflection lookups.
     *
     * @return null|FunctionDescriptor The descriptor if attribute present, null otherwise
     */
    private function resolveDescriptor(): ?FunctionDescriptor
    {
        if ($this->descriptorResolved) {
            return $this->descriptor;
        }

        $this->descriptorResolved = true;

        $reflection = new ReflectionClass($this);
        $attributes = $reflection->getAttributes(Descriptor::class);

        if ($attributes === []) {
            return null;
        }

        /** @var Descriptor $attribute */
        $attribute = $attributes[0]->newInstance();

        /** @var class-string<DescriptorInterface> $descriptorClass */
        $descriptorClass = $attribute->class;

        $this->descriptor = $descriptorClass::create();

        return $this->descriptor;
    }
}

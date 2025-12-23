<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Repositories;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Discovery\ArgumentData;
use Cline\Forrst\Discovery\ErrorDefinitionData;
use Cline\Forrst\Exceptions\ExactVersionNotFoundException;
use Cline\Forrst\Exceptions\FunctionAlreadyRegisteredException;
use Cline\Forrst\Exceptions\FunctionNotFoundException;
use Cline\Forrst\Exceptions\ReservedNamespaceException;
use Cline\Forrst\Exceptions\StabilityVersionNotFoundException;
use Cline\Forrst\Exceptions\VersionNotFoundException;
use Cline\Forrst\Rules\SemanticVersion;
use Cline\Forrst\Urn;
use Illuminate\Support\Facades\App;

use function array_filter;
use function array_values;
use function in_array;
use function is_string;
use function str_starts_with;
use function usort;
use function version_compare;

/**
 * Central registry for versioned Forrst function implementations.
 *
 * Manages function registration and provides intelligent version resolution
 * for incoming RPC calls. Functions are indexed by name@version, enabling
 * multiple versions of the same function to coexist. Supports flexible version
 * resolution through exact semver matching, stability aliases (stable, beta, etc.),
 * or automatic selection of the latest stable version.
 *
 * Enforces reserved namespace protection to prevent user functions from using
 * system namespaces like "forrst.*".
 *
 * Version resolution strategies:
 * - Exact: "3.0.0-beta.1" → matches only that specific version
 * - Stability: "beta" → selects highest beta version
 * - Default: null → selects highest stable version
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/protocol
 */
final class FunctionRepository
{
    /**
     * Recognized stability aliases for version resolution.
     *
     * Enables version requests like "beta" to resolve to the latest beta version
     * without requiring clients to specify exact version numbers.
     */
    private const array STABILITY_ALIASES = ['stable', 'alpha', 'beta', 'rc'];

    /**
     * Registered function instances indexed by name@version key.
     *
     * Example key: "orders.create@1.0.0"
     *
     * @var array<string, FunctionInterface>
     */
    private array $functions = [];

    /**
     * Index mapping function names to their available version strings.
     *
     * Enables quick lookups of all versions for a given function name without
     * iterating through the full functions array.
     *
     * @var array<string, array<string>>
     */
    private array $versionIndex = [];

    /**
     * Creates a repository and optionally pre-registers functions.
     *
     * @param array<int, FunctionInterface|string> $functions Function instances or class names to register.
     *                                                        Class names are resolved from the Laravel container.
     */
    public function __construct(array $functions = [])
    {
        foreach ($functions as $function) {
            $this->register($function);
        }
    }

    /**
     * Returns all registered function instances.
     *
     * @return array<string, FunctionInterface> Functions indexed by name@version key
     */
    public function all(): array
    {
        return $this->functions;
    }

    /**
     * Retrieves all registered versions for a specific function name.
     *
     * @param string $name Function name (e.g., "orders.create")
     *
     * @return array<string> Version strings (e.g., ["1.0.0", "1.1.0", "2.0.0-beta.1"])
     */
    public function getVersions(string $name): array
    {
        return $this->versionIndex[$name] ?? [];
    }

    /**
     * Resolves a function by name and optional version specifier.
     *
     * Resolution strategies:
     * - Exact semver (e.g., "3.0.0-beta.1"): Returns exact match
     * - Stability alias (e.g., "beta"): Returns latest version with that stability
     * - Null/omitted: Returns latest stable version
     *
     * @param string      $name    Function name (e.g., "orders.create")
     * @param null|string $version Version specifier (semver, stability alias, or null)
     *
     * @throws FunctionNotFoundException When no function with the given name exists
     * @throws VersionNotFoundException  When no matching version is found
     *
     * @return FunctionInterface Resolved function instance
     */
    public function resolve(string $name, ?string $version = null): FunctionInterface
    {
        $versions = $this->versionIndex[$name] ?? [];

        if ($versions === []) {
            throw FunctionNotFoundException::create($name);
        }

        // Stability alias resolution (alpha, beta, rc, stable)
        if ($version !== null && in_array($version, self::STABILITY_ALIASES, true)) {
            return $this->resolveByStability($name, $version, $versions);
        }

        // Exact version resolution
        if ($version !== null) {
            return $this->resolveExactVersion($name, $version, $versions);
        }

        // Default: latest stable
        return $this->resolveByStability($name, 'stable', $versions);
    }

    /**
     * Retrieves a function by name and optional version.
     *
     * When version is null, resolves to the latest stable version.
     * Use resolve() for advanced version resolution strategies.
     *
     * @param  string                    $name    Function name
     * @param  string                    $version Exact version string (optional)
     * @throws FunctionNotFoundException When the function is not registered
     * @return FunctionInterface         Registered function instance
     */
    public function get(string $name, ?string $version = null): FunctionInterface
    {
        if ($version === null) {
            return $this->resolve($name);
        }

        $key = $this->makeKey($name, $version);
        $function = $this->functions[$key] ?? null;

        if ($function === null) {
            throw FunctionNotFoundException::create($name);
        }

        return $function;
    }

    /**
     * Registers a new function in the repository.
     *
     * Accepts either a function instance or a class name that will be resolved
     * from the container. Prevents duplicate registration of the same name@version.
     *
     * @param FunctionInterface|string $function Function class name or instance to register
     *
     * @throws FunctionAlreadyRegisteredException When attempting to register a duplicate name@version
     * @throws ReservedNamespaceException         When attempting to register in a reserved namespace
     */
    public function register(string|FunctionInterface $function): void
    {
        if (is_string($function)) {
            /** @var FunctionInterface $function */
            $function = App::make($function);
        }

        $name = $function->getUrn();
        $version = $function->getVersion();
        $key = $this->makeKey($name, $version);

        // Check for reserved namespace violations
        $this->validateNamespace($function, $name);

        if (isset($this->functions[$key])) {
            throw FunctionAlreadyRegisteredException::forFunction($key);
        }

        $this->functions[$key] = $function;

        // Update version index
        if (!isset($this->versionIndex[$name])) {
            $this->versionIndex[$name] = [];
        }

        $this->versionIndex[$name][] = $version;
    }

    /**
     * Validates a function's metadata for type safety and format compliance.
     *
     * Performs runtime validation to ensure function metadata adheres to
     * expected types and formats. Should be called during registration or
     * discovery generation to catch configuration errors early.
     *
     * @param FunctionInterface $function Function instance to validate
     *
     * @throws \InvalidArgumentException When validation fails
     */
    public function validateFunction(FunctionInterface $function): void
    {
        // Validate URN format
        if (!Urn::isValid($function->getUrn())) {
            throw new \InvalidArgumentException(
                sprintf('Invalid function URN: %s', $function->getUrn())
            );
        }

        // Validate semantic version format
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z-]+(\.[0-9A-Za-z-]+)*)?$/', $function->getVersion())) {
            throw new \InvalidArgumentException(
                sprintf('Invalid semantic version: %s', $function->getVersion())
            );
        }

        // Validate arguments are ArgumentData instances or arrays
        foreach ($function->getArguments() as $index => $argument) {
            if (!$argument instanceof ArgumentData && !is_array($argument)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Argument at index %d must be ArgumentData or array, %s given',
                        $index,
                        get_debug_type($argument)
                    )
                );
            }
        }

        // Validate errors are ErrorDefinitionData instances or arrays
        foreach ($function->getErrors() as $index => $error) {
            if (!$error instanceof ErrorDefinitionData && !is_array($error)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Error at index %d must be ErrorDefinitionData or array, %s given',
                        $index,
                        get_debug_type($error)
                    )
                );
            }
        }

        // Validate side effects are valid strings
        if ($function->getSideEffects() !== null) {
            $validSideEffects = ['create', 'update', 'delete'];
            foreach ($function->getSideEffects() as $sideEffect) {
                if (!in_array($sideEffect, $validSideEffects, true)) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Invalid side effect "%s". Must be one of: %s',
                            $sideEffect,
                            implode(', ', $validSideEffects)
                        )
                    );
                }
            }
        }
    }

    /**
     * Resolves a function by stability level.
     *
     * Finds all versions matching the requested stability and returns
     * the highest version number among them.
     *
     * @param string        $name      Function name
     * @param string        $stability Stability level (stable, alpha, beta, rc)
     * @param array<string> $versions  Available versions
     *
     * @throws VersionNotFoundException When no version matches the stability
     *
     * @return FunctionInterface Resolved function instance
     */
    private function resolveByStability(string $name, string $stability, array $versions): FunctionInterface
    {
        // Filter versions by stability
        $matching = array_filter($versions, fn (string $version): bool => SemanticVersion::stability($version) === $stability);

        if ($matching === []) {
            throw StabilityVersionNotFoundException::create($name, $stability, $versions);
        }

        // Sort by version descending and take the highest
        $matching = array_values($matching);
        usort($matching, fn (string $a, string $b): int => version_compare($b, $a));

        $latestVersion = $matching[0];
        $key = $this->makeKey($name, $latestVersion);

        return $this->functions[$key];
    }

    /**
     * Resolves a function by exact version match.
     *
     * @param string        $name     Function name
     * @param string        $version  Exact version to match
     * @param array<string> $versions Available versions
     *
     * @throws VersionNotFoundException When the exact version is not found
     *
     * @return FunctionInterface Resolved function instance
     */
    private function resolveExactVersion(string $name, string $version, array $versions): FunctionInterface
    {
        if (!in_array($version, $versions, true)) {
            throw ExactVersionNotFoundException::create($name, $version, $versions);
        }

        $key = $this->makeKey($name, $version);

        return $this->functions[$key];
    }

    /**
     * Creates a unique key for a function name and version.
     *
     * @param  string $name    Function name
     * @param  string $version Version string
     * @return string Key in format "name@version"
     */
    private function makeKey(string $name, string $version): string
    {
        return $name.'@'.$version;
    }

    /**
     * Validate that a function does not use a reserved namespace.
     *
     * System functions (those in the Cline\Forrst\Functions namespace) are allowed
     * to use reserved namespaces like "forrst.*". User-defined functions are not.
     *
     * @param FunctionInterface $function   The function being registered
     * @param string            $methodName The function name
     *
     * @throws ReservedNamespaceException When a user function uses a reserved namespace
     */
    private function validateNamespace(FunctionInterface $function, string $methodName): void
    {
        // System functions in Cline\Forrst\Functions namespace are allowed to use reserved namespaces
        if (str_starts_with($function::class, 'Cline\\Forrst\\Functions\\')) {
            return;
        }

        $violatedPrefix = ReservedNamespaceException::getViolatedPrefix($methodName);

        if ($violatedPrefix !== null) {
            throw ReservedNamespaceException::forFunction($methodName, $violatedPrefix);
        }
    }
}

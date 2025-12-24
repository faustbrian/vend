<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Discovery\Functions;

use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\UnwrappedResponseInterface;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Discovery\ComponentsData;
use Cline\Forrst\Discovery\DiscoveryData;
use Cline\Forrst\Discovery\DiscoveryServerData;
use Cline\Forrst\Discovery\InfoData;
use Cline\Forrst\Discovery\LicenseData;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Extensions\Discovery\Descriptors\DescribeDescriptor;
use Cline\Forrst\Facades\Server as Facade;
use Cline\Forrst\Functions\AbstractFunction;
use Cline\Forrst\Rules\SemanticVersion;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

use function assert;
use function collect;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;

/**
 * Implements the standard Forrst service discovery function (forrst.describe).
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/
 */
#[Descriptor(DescribeDescriptor::class)]
final class DescribeFunction extends AbstractFunction implements UnwrappedResponseInterface
{
    /**
     * Current Discovery specification version.
     */
    public const string DISCOVERY_VERSION = '0.1.0';

    /**
     * Keys that must be preserved even when empty.
     */
    private const array PRESERVE_KEYS = ['functions', 'arguments', 'errors', 'links'];

    /**
     * Generates and returns the Forrst Discovery document.
     *
     * @return array<string, mixed> Complete discovery document or single function descriptor
     */
    public function handle(): array
    {
        $arguments = $this->requestObject->call->arguments ?? [];
        $functionName = $arguments['function'] ?? null;
        $functionVersion = $arguments['version'] ?? null;

        if ($functionName !== null) {
            assert(is_string($functionName));
            assert($functionVersion === null || is_string($functionVersion));

            return $this->describeSingleFunction($functionName, $functionVersion);
        }

        return $this->describeFullService();
    }

    /**
     * Recursively filters an array by removing null and empty values.
     *
     * @param  array<string, mixed> $array
     * @return array<string, mixed>
     */
    private static function filterRecursive(array $array): array
    {
        foreach ($array as $key => &$value) {
            if (is_array($value)) {
                // @phpstan-ignore-next-line - Dynamic array structure from discovery data
                $value = self::filterRecursive($value);

                if ($value === [] && !in_array($key, self::PRESERVE_KEYS, true)) {
                    unset($array[$key]);
                }

                continue;
            }

            if ((bool) $value) {
                continue;
            }

            if (is_bool($value)) {
                continue;
            }

            if (is_numeric($value) && $value === 0) {
                continue;
            }

            unset($array[$key]);
        }

        unset($value);

        return $array;
    }

    /**
     * Generates the full discovery document.
     *
     * @return array<string, mixed>
     */
    private function describeFullService(): array
    {
        $errors = $this->buildStandardErrors();

        /** @var array<int, array<string, mixed>> $functions */
        $functions = [];

        /** @var FunctionInterface $serverFunction */
        foreach (Facade::getFunctionRepository()->all() as $serverFunction) {
            if (!$serverFunction->isDiscoverable()) {
                continue;
            }

            $functions[] = $this->buildFunctionDescriptor($serverFunction, $errors);
        }

        $discovery = DiscoveryData::from([
            'forrst' => ProtocolData::VERSION,
            'discovery' => self::DISCOVERY_VERSION,
            'info' => InfoData::from([
                'title' => Facade::getName(),
                'version' => Facade::getVersion(),
                'license' => LicenseData::from(['name' => 'Proprietary']),
            ]),
            'servers' => [
                DiscoveryServerData::from([
                    'name' => App::environment(),
                    'url' => URL::to(Facade::getRoutePath()),
                ]),
            ],
            'functions' => $functions,
            'components' => ComponentsData::from([
                'errors' => collect($errors)->keyBy('code')->all(),
            ]),
        ]);

        /** @var array<string, mixed> $result */
        $result = $discovery->toArray();

        return self::filterRecursive($result);
    }

    /**
     * Generates a descriptor for a single function.
     *
     * @return array<string, mixed>
     */
    private function describeSingleFunction(string $name, ?string $version): array
    {
        $repository = Facade::getFunctionRepository();

        /** @var FunctionInterface $serverFunction */
        foreach ($repository->all() as $serverFunction) {
            if ($serverFunction->getUrn() !== $name) {
                continue;
            }

            if ($version !== null && $serverFunction->getVersion() !== $version) {
                continue;
            }

            /** @var array<string, mixed> $descriptor */
            $descriptor = $this->buildFunctionDescriptor($serverFunction, $this->buildStandardErrors());

            return self::filterRecursive($descriptor);
        }

        return [];
    }

    /**
     * Builds a function descriptor for the discovery document.
     *
     * @param  array<int, array<string, mixed>> $standardErrors
     * @return array<string, mixed>
     */
    private function buildFunctionDescriptor(FunctionInterface $function, array $standardErrors): array
    {
        $version = $function->getVersion();

        return [
            'name' => $function->getUrn(),
            'version' => $version,
            'stability' => SemanticVersion::stability($version),
            'summary' => $function->getSummary(),
            'description' => $function->getDescription(),
            'tags' => $function->getTags(),
            'arguments' => $function->getArguments(),
            'result' => $function->getResult(),
            'errors' => [
                ...$standardErrors,
                ...$function->getErrors(),
            ],
            'query' => $function->getQuery(),
            'deprecated' => $function->getDeprecated(),
            'sideEffects' => $function->getSideEffects(),
            'examples' => $function->getExamples(),
            'simulations' => $function->getSimulations(),
            'links' => $function->getLinks(),
            'externalDocs' => $function->getExternalDocs(),
            'extensions' => $function->getExtensions(),
        ];
    }

    /**
     * Builds the standard error definitions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildStandardErrors(): array
    {
        return [
            ['code' => ErrorCode::ParseError->value, 'message' => 'Parse error'],
            ['code' => ErrorCode::InvalidRequest->value, 'message' => 'Invalid request'],
            ['code' => ErrorCode::FunctionNotFound->value, 'message' => 'Function not found'],
            ['code' => ErrorCode::InvalidArguments->value, 'message' => 'Invalid arguments'],
            ['code' => ErrorCode::SchemaValidationFailed->value, 'message' => 'Validation error'],
            ['code' => ErrorCode::InternalError->value, 'message' => 'Internal error'],
            ['code' => ErrorCode::InternalError->value, 'message' => 'Server error'],
            ['code' => ErrorCode::Unavailable->value, 'message' => 'Service unavailable'],
            ['code' => ErrorCode::Unauthorized->value, 'message' => 'Unauthorized'],
            ['code' => ErrorCode::Forbidden->value, 'message' => 'Forbidden'],
            ['code' => ErrorCode::RateLimited->value, 'message' => 'Rate limited'],
        ];
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Jobs;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\UnwrappedResponseInterface;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Exceptions\ExceptionMapper;
use Cline\Forrst\Exceptions\InvalidDataException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Throwable;

use function array_filter;
use function array_keys;
use function assert;
use function call_user_func;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_subclass_of;
use function sprintf;

/**
 * Executes a Forrst function with automatic error handling and response formatting.
 *
 * Orchestrates function invocation by resolving parameters, handling Data object validation,
 * and wrapping results in proper Forrst response format. Automatically maps request arguments
 * to function parameters, supporting both scalar values and Spatie Laravel Data objects.
 * Catches all exceptions and converts them to standardized Forrst error responses.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/
 *
 * @psalm-immutable
 */
final readonly class CallFunction
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Create a new function execution job instance.
     *
     * @param FunctionInterface $function      The Forrst function instance to execute, resolved
     *                                         from the server's registered functions based on
     *                                         the request's function name.
     * @param RequestObjectData $requestObject The parsed Forrst request containing function name,
     *                                         arguments, and request ID for correlation with
     *                                         the response.
     */
    public function __construct(
        private FunctionInterface $function,
        private RequestObjectData $requestObject,
    ) {}

    /**
     * Execute the Forrst function and return the formatted response.
     *
     * Invokes the function's handle method with resolved parameters, wrapping the result
     * in a Forrst ResponseData object. If the function implements UnwrappedResponseInterface,
     * returns the raw result array for custom response handling. All exceptions are caught
     * and converted to error responses via the ExceptionMapper.
     *
     * @throws InvalidDataException When Data object validation fails during parameter resolution
     *
     * @return array<string, mixed>|ResponseData The function result wrapped in a Forrst response, or raw array for unwrapped responses
     */
    public function handle(): array|ResponseData
    {
        try {
            $this->function->setRequest($this->requestObject);

            $result = App::call(
                // @phpstan-ignore-next-line
                [$this->function, 'handle'],
                [
                    'requestObject' => $this->requestObject,
                    ...$this->resolveParameters($this->function, $this->requestObject->getArguments() ?? []),
                ],
            );

            if ($this->function instanceof UnwrappedResponseInterface) {
                /** @var array<string, mixed> $result */
                return $result;
            }

            return ResponseData::success(
                result: $result,
                id: $this->requestObject->id,
            );
        } catch (Throwable $throwable) {
            return ResponseData::fromException(
                exception: ExceptionMapper::execute($throwable),
                id: $this->requestObject->id,
            );
        }
    }

    /**
     * Resolve function parameters from the request data.
     *
     * Maps request arguments to function parameters by name, with automatic conversion
     * for Spatie Laravel Data objects and support for snake_case to camelCase mapping.
     * Special handling for the 'data' parameter which receives all arguments when typed
     * as array. The 'requestObject' parameter is excluded from mapping as it's provided
     * internally.
     *
     * @param FunctionInterface    $function  The function whose parameters need resolution via reflection
     * @param array<string, mixed> $arguments The raw request arguments to resolve and map to parameters
     *
     * @throws InvalidDataException When Data object validation fails during parameter conversion
     *
     * @return array<string, mixed> The resolved parameters keyed by parameter name, ready for injection
     */
    private function resolveParameters(FunctionInterface $function, array $arguments): array
    {
        if (count($arguments) < 1) {
            return [];
        }

        $parameters = new ReflectionClass($function)->getMethod('handle')->getParameters();
        $parametersMapped = [];
        $resolutionErrors = [];

        foreach ($parameters as $parameter) {
            $parameterName = $parameter->getName();

            // This is an internal parameter, we don't want to map it.
            if ($parameterName === 'requestObject') {
                continue;
            }

            try {
                $value = $this->resolveParameter($parameter, $arguments);

                if ($value !== null || $parameter->allowsNull()) {
                    $parametersMapped[$parameterName] = $value;
                } elseif (!$parameter->isOptional()) {
                    // Required parameter resolved to null
                    $resolutionErrors[] = sprintf(
                        'Required parameter "%s" could not be resolved',
                        $parameterName,
                    );
                }
            } catch (Throwable $e) {
                $resolutionErrors[] = sprintf(
                    'Failed to resolve parameter "%s": %s',
                    $parameterName,
                    $e->getMessage(),
                );
            }
        }

        if (count($resolutionErrors) > 0) {
            throw new \InvalidArgumentException(
                'Parameter resolution failed: '.implode('; ', $resolutionErrors),
            );
        }

        return $parametersMapped;
    }

    /**
     * Resolve a single parameter from the request arguments.
     *
     * @param \ReflectionParameter $parameter The parameter to resolve
     * @param array<string, mixed> $arguments The raw request arguments
     *
     * @throws InvalidDataException When Data object validation fails
     *
     * @return mixed The resolved parameter value
     */
    private function resolveParameter(\ReflectionParameter $parameter, array $arguments): mixed
    {
        $parameterName = $parameter->getName();
        $parameterType = $parameter->getType();

        if ($parameterType instanceof ReflectionNamedType) {
            $parameterType = $parameterType->getName();
        }

        $parameterValue = Arr::get($arguments, $parameterName) ?? Arr::get($arguments, Str::snake($parameterName, '.'));

        if (is_subclass_of((string) $parameterType, Data::class)) {
            try {
                $payload = $parameter->getName() === 'data' ? $arguments : $parameterValue;
                assert(is_array($payload));

                return call_user_func(
                    [(string) $parameterType, 'validateAndCreate'],
                    $payload,
                );
            } catch (ValidationException $exception) {
                throw InvalidDataException::create($exception);
            }
        }

        if ($parameterType === 'array' && $parameter->getName() === 'data') {
            return $arguments;
        }

        return $parameterValue;
    }
}

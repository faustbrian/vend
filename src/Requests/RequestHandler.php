<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Requests;

use Cline\Forrst\Contracts\ProtocolInterface;
use Cline\Forrst\Data\ProtocolData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\RequestResultData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Events\ExecutingFunction;
use Cline\Forrst\Events\FunctionExecuted;
use Cline\Forrst\Events\RequestValidated;
use Cline\Forrst\Events\SendingResponse;
use Cline\Forrst\Exceptions\AbstractRequestException;
use Cline\Forrst\Exceptions\ForbiddenException;
use Cline\Forrst\Exceptions\InternalErrorException;
use Cline\Forrst\Exceptions\ParseErrorException;
use Cline\Forrst\Exceptions\RequestValidationFailedException;
use Cline\Forrst\Exceptions\StructurallyInvalidRequestException;
use Cline\Forrst\Exceptions\UnauthorizedException;
use Cline\Forrst\Facades\Server;
use Cline\Forrst\Jobs\CallFunction;
use Cline\Forrst\Protocols\ForrstProtocol;
use Cline\Forrst\Rules\Identifier;
use Cline\Forrst\Rules\SemanticVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

use function array_is_list;
use function dispatch_sync;
use function event;
use function hrtime;
use function is_array;
use function is_string;
use function round;
use function throw_if;
use function throw_unless;

/**
 * Central request processor for the Forrst RPC protocol.
 *
 * Orchestrates the complete request lifecycle: parsing and validation of incoming
 * requests, function resolution and dispatch, response construction, and error handling.
 * Enforces protocol compliance through strict validation against the Forrst specification.
 *
 * Supports event-driven extension through hooks at key lifecycle points (validation,
 * execution, response). Does not support batch requests - clients requiring concurrency
 * should use HTTP/2 request multiplexing or connection pooling.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/protocol
 *
 * @psalm-immutable
 */
#[Singleton()]
final readonly class RequestHandler
{
    /**
     * Creates a request handler with optional custom protocol.
     *
     * @param ProtocolInterface $protocol Protocol for message serialization. Defaults to ForrstProtocol
     *                                    (JSON-based). Custom protocols enable alternative formats
     *                                    like MessagePack or Protocol Buffers.
     */
    public function __construct(
        private ProtocolInterface $protocol = new ForrstProtocol(
        ),
    ) {}

    /**
     * Processes a Forrst request from an already-parsed array structure.
     *
     * Convenience factory for handling requests that have already been deserialized
     * (e.g., from HTTP middleware or testing frameworks).
     *
     * @param array<string, mixed>   $request  Request data in Forrst protocol format
     * @param null|ProtocolInterface $protocol Optional protocol override for response encoding
     *
     * @return RequestResultData Result with response data and HTTP status code
     */
    public static function createFromArray(array $request, ?ProtocolInterface $protocol = null): RequestResultData
    {
        return ($protocol instanceof ProtocolInterface ? new self($protocol) : new self())->handle($request);
    }

    /**
     * Processes a Forrst request from a serialized string payload.
     *
     * Convenience factory for handling raw request strings (e.g., from HTTP request body).
     * The protocol's decode method is used to parse the string into an array structure.
     *
     * @param string                 $request  Serialized request payload (typically JSON)
     * @param null|ProtocolInterface $protocol Protocol for request decoding and response encoding
     *
     * @return RequestResultData Result with response data and HTTP status code
     */
    public static function createFromString(string $request, ?ProtocolInterface $protocol = null): RequestResultData
    {
        return ($protocol instanceof ProtocolInterface ? new self($protocol) : new self())->handle($request);
    }

    /**
     * Processes a Forrst request and returns the result.
     *
     * Handles the complete request lifecycle: parsing, validation, function dispatch,
     * and response construction. Maps exceptions to appropriate Forrst error responses
     * with correct HTTP status codes.
     *
     * @param array<string, mixed>|string $request Forrst request as array or serialized string
     *
     * @throws ParseErrorException When deserialization fails
     *
     * @return RequestResultData Result containing response data and HTTP status code
     */
    public function handle(array|string $request): RequestResultData
    {
        $startTime = hrtime(true);
        $requestData = null;

        try {
            $requestData = $this->parse($request);

            $this->validate($requestData);

            $requestObject = RequestObjectData::from($requestData);

            // EVENT: Request validated
            $validatedEvent = new RequestValidated($requestObject);
            event($validatedEvent);

            if ($validatedEvent->getResponse() instanceof ResponseData) {
                return $this->result($validatedEvent->getResponse(), $startTime);
            }

            // EVENT: Function executing (per-extension events)
            foreach ($requestObject->extensions ?? [] as $extensionData) {
                $executingEvent = new ExecutingFunction($requestObject, $extensionData);
                event($executingEvent);

                if ($executingEvent->getResponse() instanceof ResponseData) {
                    return $this->result($executingEvent->getResponse(), $startTime);
                }
            }

            $function = Server::getFunctionRepository()->resolve(
                $requestObject->getFunction(),
                $requestObject->getVersion(),
            );

            /** @var array<string, mixed>|ResponseData $response */
            $response = dispatch_sync(
                new CallFunction($function, $requestObject),
            );

            // Unwrapped responses (e.g., forrst.describe) return raw arrays
            if (is_array($response)) {
                return RequestResultData::from([
                    'data' => $response,
                    'statusCode' => 200,
                ]);
            }

            // EVENT: Function executed (per-extension events)
            foreach ($requestObject->extensions ?? [] as $extensionData) {
                $executedEvent = new FunctionExecuted($requestObject, $extensionData, $response);
                event($executedEvent);
                $response = $executedEvent->getResponse();
            }

            // EVENT: Response sending
            $sendingEvent = new SendingResponse($requestObject, $response);
            event($sendingEvent);

            return $this->result($sendingEvent->getResponse(), $startTime);
        } catch (Throwable $throwable) {
            // Use parsed data if available, otherwise use original request
            return $this->handleException($throwable, $requestData ?? $request, $startTime);
        }
    }

    /**
     * Build a request result from a response with duration metadata.
     *
     * @param  ResponseData      $response  The response data
     * @param  int               $startTime Start time in nanoseconds from hrtime(true)
     * @return RequestResultData Result with HTTP status 200
     */
    private function result(ResponseData $response, int $startTime): RequestResultData
    {
        return RequestResultData::from([
            'data' => $this->withDuration($response, $startTime),
            'statusCode' => 200,
        ]);
    }

    /**
     * Handle exceptions and convert them to appropriate Forrst error responses.
     *
     * @param  Throwable                   $throwable The caught exception
     * @param  array<string, mixed>|string $request   Original request for extracting ID
     * @param  int                         $startTime Start time in nanoseconds from hrtime(true)
     * @return RequestResultData           Error response with appropriate status code
     */
    private function handleException(Throwable $throwable, array|string $request, int $startTime): RequestResultData
    {
        // ID is required - extract from request or generate
        $id = $this->extractRequestId($request);

        if ($throwable instanceof AbstractRequestException) {
            return RequestResultData::from([
                'data' => $this->withDuration(ResponseData::fromException($throwable, $id), $startTime),
                'statusCode' => $throwable->getStatusCode(),
            ]);
        }

        // @codeCoverageIgnoreStart
        if ($throwable instanceof AuthenticationException) {
            return RequestResultData::from([
                'data' => $this->withDuration(ResponseData::fromException(UnauthorizedException::create(), $id), $startTime),
                'statusCode' => 401,
            ]);
        }

        if ($throwable instanceof AuthorizationException) {
            return RequestResultData::from([
                'data' => $this->withDuration(ResponseData::fromException(ForbiddenException::create(), $id), $startTime),
                'statusCode' => 403,
            ]);
        }

        return RequestResultData::from([
            'data' => $this->withDuration(
                ResponseData::fromException(
                    InternalErrorException::create($throwable),
                    $id,
                ),
                $startTime,
            ),
            'statusCode' => 500,
        ]);
        // @codeCoverageIgnoreEnd
    }

    /**
     * Validates a request object against Forrst protocol specification.
     *
     * Ensures the request contains required fields (protocol, id, call.function) with correct
     * types and values. Protocol must be an object with name and version. ID is required.
     *
     * @param array<string, mixed> $data Request object to validate
     *
     * @throws StructurallyInvalidRequestException When validation fails
     */
    private function validate(array $data): void
    {
        $validator = Validator::make(
            $data,
            [
                'protocol' => ['required', 'array'],
                'protocol.name' => ['required', 'string', 'in:'.ProtocolData::NAME],
                'protocol.version' => ['required', 'string', 'in:'.ProtocolData::VERSION],
                'id' => ['required', 'string', new Identifier()],
                'call' => ['required', 'array'],
                'call.function' => ['required', 'string'],
                'call.version' => ['nullable', 'string', new SemanticVersion()],
                'call.arguments' => ['nullable', 'array'],
                'context' => ['nullable', 'array'],
            ],
        );

        throw_if($validator->fails(), RequestValidationFailedException::fromValidator($validator));
    }

    /**
     * Parses and validates the request structure.
     *
     * Deserializes strings using the configured protocol and validates basic structure.
     *
     * @param array<string, mixed>|string $request Raw request data as array or serialized string
     *
     * @throws ParseErrorException                 When deserialization fails
     * @throws StructurallyInvalidRequestException When the request is a batch (array list)
     *
     * @return array<string, mixed> Parsed request data
     */
    private function parse(array|string $request): array
    {
        if (is_string($request)) {
            try {
                $request = $this->protocol->decodeRequest($request);
            } catch (Throwable) {
                throw ParseErrorException::create();
            }
        }

        throw_if($request === [], StructurallyInvalidRequestException::create());

        // Forrst does not support batch requests - must be associative array
        throw_unless(
            $this->isAssociative($request),
            StructurallyInvalidRequestException::create([
                [
                    'status' => '400',
                    'source' => ['pointer' => '/'],
                    'title' => 'Invalid request',
                    'detail' => 'Batch requests are not supported. Send requests individually or use HTTP pooling.',
                ],
            ]),
        );

        return $request;
    }

    /**
     * Check if an array is associative (not a list).
     *
     * @param  array<mixed> $array Array to check
     * @return bool         True if associative, false if sequential
     */
    private function isAssociative(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return !array_is_list($array);
    }

    /**
     * Extract request ID from array or string request.
     *
     * Attempts to parse string requests to extract ID before error handling,
     * ensuring request/response correlation is maintained even for parse errors.
     *
     * @param  array<string, mixed>|string $request Request to extract ID from
     * @return string                      Request ID or generated ULID
     */
    private function extractRequestId(array|string $request): string
    {
        // If already array, extract ID directly
        if (is_array($request)) {
            if (isset($request['id']) && is_string($request['id'])) {
                return $request['id'];
            }

            return Str::ulid()->toString();
        }

        // For string requests, attempt JSON decode to extract ID
        try {
            $decoded = json_decode($request, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id'])) {
                return $decoded['id'];
            }
        } catch (\JsonException) {
            // JSON decode failed - return generated ID
        }

        return Str::ulid()->toString();
    }

    /**
     * Add duration meta to a response.
     *
     * Per Forrst spec, responses SHOULD include meta.duration with the processing
     * time in milliseconds using the structured format {value, unit}.
     *
     * @param  ResponseData $response  The response to augment
     * @param  int          $startTime Start time in nanoseconds from hrtime(true)
     * @return ResponseData New response with duration meta added
     */
    private function withDuration(ResponseData $response, int $startTime): ResponseData
    {
        $durationNs = hrtime(true) - $startTime;
        $durationMs = (int) round($durationNs / 1_000_000);

        $meta = $response->meta ?? [];
        $meta['duration'] = [
            'value' => $durationMs,
            'unit' => 'millisecond',
        ];

        return new ResponseData(
            protocol: $response->protocol,
            id: $response->id,
            result: $response->result,
            errors: $response->errors,
            extensions: $response->extensions,
            meta: $meta,
        );
    }
}

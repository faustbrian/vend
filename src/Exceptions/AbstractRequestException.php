<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Exceptions;

use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\Errors\SourceData;
use Cline\Forrst\Enums\ErrorCode;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;

/**
 * Base exception class for Forrst request errors.
 *
 * Part of the Forrst protocol exception hierarchy. Provides the foundation for all
 * Forrst error handling by encapsulating error data and standardized error response
 * formatting. All Forrst exceptions extend this class to ensure consistent error
 * structure across the RPC server implementation.
 *
 * Exceptions are structured around the Forrst error specification with support for
 * error codes, messages, source locations, and additional details. The class handles
 * conversion between exception objects and error response arrays, automatic HTTP
 * status code mapping, and debug information injection when enabled.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/errors
 */
abstract class AbstractRequestException extends Exception implements RpcException
{
    /**
     * Create a new request exception instance.
     *
     * @param ErrorData $error The error data object containing the error code, human-readable
     *                         message, optional source location, and additional details. This
     *                         encapsulates all information needed to render a Forrst compliant
     *                         error response to the client.
     */
    public function __construct(
        public readonly ErrorData $error,
    ) {
        parent::__construct(
            $this->getErrorMessage(),
        );
    }

    /**
     * Get the Forrst error code.
     *
     * @return string The Forrst error code string from the error data object
     */
    public function getErrorCode(): string
    {
        return $this->error->code;
    }

    /**
     * Get the error message.
     *
     * @return string The human-readable error message from the error data object
     */
    public function getErrorMessage(): string
    {
        return $this->error->message;
    }

    /**
     * Get additional error details.
     *
     * @return null|array<string, mixed> Optional additional context and debugging information
     *                                   specific to this error instance
     */
    public function getErrorDetails(): ?array
    {
        return $this->error->details;
    }

    /**
     * Get the error source location.
     *
     * @return null|SourceData The source location identifying which parameter or pointer
     *                         caused the error, useful for validation errors
     */
    public function getErrorSource(): ?SourceData
    {
        return $this->error->source;
    }

    /**
     * Check if this error is retryable.
     *
     * Determines whether the client should attempt to retry the request based on the
     * error code. Transient errors like rate limits and temporary failures return true.
     * Permanent errors like validation failures and not found errors return false.
     * Retry semantics are primarily communicated via the Retry extension.
     *
     * @return bool Whether the client should retry this request
     */
    public function isRetryable(): bool
    {
        $errorCode = ErrorCode::tryFrom($this->error->code);

        return $errorCode?->isRetryable() ?? false;
    }

    /**
     * Get the HTTP status code for this error.
     *
     * @return int The HTTP status code appropriate for this error type
     */
    public function getStatusCode(): int
    {
        return $this->error->toStatusCode();
    }

    /**
     * Get HTTP headers to include in the error response.
     *
     * @return array<string, string> Array of HTTP headers to include in the response.
     *                               Default implementation returns empty array, but
     *                               subclasses may override to add headers like Retry-After
     */
    public function getHeaders(): array
    {
        return [];
    }

    /**
     * Convert the exception to an ErrorData object.
     *
     * @return ErrorData The error data representation of this exception
     */
    public function toError(): ErrorData
    {
        return $this->error;
    }

    /**
     * Convert the exception to an array representation.
     *
     * Serializes the exception into a Forrst compliant error array structure. When debug
     * mode is enabled, automatically injects debug information including file location,
     * line number, and stack trace into the error details for developer troubleshooting.
     *
     * @return array<string, mixed> The error array with code, message, source, and details
     */
    public function toArray(): array
    {
        $message = $this->error->toArray();

        if (App::hasDebugModeEnabled()) {
            Arr::set(
                $message,
                'details.debug',
                [
                    'file' => $this->getFile(),
                    'line' => $this->getLine(),
                    'trace' => $this->getTraceAsString(),
                ],
            );
        }

        /** @var array<string, mixed> */
        return $message;
    }

    /**
     * Get contextual data for structured logging.
     *
     * Provides comprehensive error information formatted for logging systems.
     * Includes error code, message, file location, retryability status, source
     * information, and additional details for debugging and monitoring.
     *
     * @return array<string, mixed> Structured logging context with all relevant error data
     */
    public function getLogContext(): array
    {
        return [
            'error_code' => $this->getErrorCode(),
            'error_message' => $this->getErrorMessage(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'retryable' => $this->isRetryable(),
            'source' => $this->error->source?->toArray(),
            'details' => $this->error->details,
        ];
    }

    /**
     * Create a new exception instance with error details.
     *
     * Factory method for constructing exception instances with standardized error data.
     * Used by concrete exception subclasses to create properly structured exceptions
     * with error codes, messages, source locations, and additional details.
     *
     * @param  ErrorCode                 $code    The Forrst error code enum value identifying the
     *                                            specific error type
     * @param  string                    $message The human-readable error message explaining what
     *                                            went wrong
     * @param  null|SourceData           $source  Optional source location identifying the parameter
     *                                            or pointer that caused the error
     * @param  null|array<string, mixed> $details Optional additional error context and debugging
     *                                            information specific to this error instance
     * @return static                    The constructed exception instance
     */
    protected static function new(
        ErrorCode $code,
        string $message,
        ?SourceData $source = null,
        ?array $details = null,
    ): static {
        // PHPStan cannot infer that 'new static' returns 'static' type in abstract classes
        // This is a known limitation when using LSB (Late Static Binding) with abstract constructors
        // Safe to suppress as all subclasses must call parent constructor accepting ErrorData
        // @phpstan-ignore-next-line
        return new static(
            new ErrorData(
                code: $code,
                message: $message,
                source: $source,
                details: $details,
            ),
        );
    }
}

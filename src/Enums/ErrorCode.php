<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Enums;

/**
 * Standardized error codes for the Forrst protocol.
 *
 * Defines all error codes used in Forrst protocol error responses. Error codes
 * provide machine-readable identifiers for error conditions, enabling clients
 * to implement appropriate error handling logic. Error code values use
 * SCREAMING_SNAKE_CASE format per the Forrst protocol specification.
 *
 * Categories include protocol errors, function errors, authentication errors,
 * resource errors, operational errors, idempotency errors, async errors,
 * maintenance errors, replay errors, and cancellation errors.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/errors
 * @see https://docs.cline.sh/forrst/protocol
 */
enum ErrorCode: string
{
    /**
     * Request body contains malformed JSON or invalid protocol structure.
     */
    case ParseError = 'PARSE_ERROR';

    /**
     * Request is missing required fields or contains invalid field values.
     */
    case InvalidRequest = 'INVALID_REQUEST';

    /**
     * Requested protocol version is not supported by the server.
     */
    case InvalidProtocolVersion = 'INVALID_PROTOCOL_VERSION';

    /**
     * Requested function does not exist on the server.
     */
    case FunctionNotFound = 'FUNCTION_NOT_FOUND';

    /**
     * Requested function version is not available or has been deprecated.
     */
    case VersionNotFound = 'VERSION_NOT_FOUND';

    /**
     * Function is temporarily disabled, typically for maintenance or deprecation.
     */
    case FunctionDisabled = 'FUNCTION_DISABLED';

    /**
     * Function arguments do not match the expected types or structure.
     */
    case InvalidArguments = 'INVALID_ARGUMENTS';

    /**
     * Function arguments failed JSON schema validation against the function definition.
     */
    case SchemaValidationFailed = 'SCHEMA_VALIDATION_FAILED';

    /**
     * Requested extension is not supported by the server implementation.
     */
    case ExtensionNotSupported = 'EXTENSION_NOT_SUPPORTED';

    /**
     * Extension cannot be used with this specific function or request context.
     */
    case ExtensionNotApplicable = 'EXTENSION_NOT_APPLICABLE';

    /**
     * Request requires authentication but none was provided or token is invalid.
     */
    case Unauthorized = 'UNAUTHORIZED';

    /**
     * Authenticated client lacks permission to perform the requested operation.
     */
    case Forbidden = 'FORBIDDEN';

    /**
     * Requested resource does not exist on the server.
     */
    case NotFound = 'NOT_FOUND';

    /**
     * Operation conflicts with current resource state (e.g., duplicate creation).
     */
    case Conflict = 'CONFLICT';

    /**
     * Requested resource previously existed but has been permanently removed.
     */
    case Gone = 'GONE';

    /**
     * Operation exceeded configured time limit before completion.
     */
    case DeadlineExceeded = 'DEADLINE_EXCEEDED';

    /**
     * Client has exceeded rate limits; requests should be throttled or retried later.
     */
    case RateLimited = 'RATE_LIMITED';

    /**
     * Unexpected server error occurred during request processing.
     */
    case InternalError = 'INTERNAL_ERROR';

    /**
     * Server is temporarily unavailable, typically during deployment or high load.
     */
    case Unavailable = 'UNAVAILABLE';

    /**
     * External dependency failed, preventing successful request completion.
     */
    case DependencyError = 'DEPENDENCY_ERROR';

    /**
     * Idempotency key was previously used with different request parameters.
     */
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';

    /**
     * Original request with this idempotency key is still processing.
     */
    case IdempotencyProcessing = 'IDEMPOTENCY_PROCESSING';

    /**
     * Requested async operation ID does not exist or has expired.
     */
    case AsyncOperationNotFound = 'ASYNC_OPERATION_NOT_FOUND';

    /**
     * Async operation completed but ended in failure state.
     */
    case AsyncOperationFailed = 'ASYNC_OPERATION_FAILED';

    /**
     * Async operation cannot be cancelled in its current state.
     */
    case AsyncCannotCancel = 'ASYNC_CANNOT_CANCEL';

    /**
     * Server is undergoing scheduled maintenance and is temporarily unavailable.
     */
    case ServerMaintenance = 'SERVER_MAINTENANCE';

    /**
     * Specific function is under maintenance and temporarily unavailable.
     */
    case FunctionMaintenance = 'FUNCTION_MAINTENANCE';

    /**
     * Requested replay operation does not exist or ID is invalid.
     */
    case ReplayNotFound = 'REPLAY_NOT_FOUND';

    /**
     * Replay operation has exceeded its retention period and can no longer be accessed.
     */
    case ReplayExpired = 'REPLAY_EXPIRED';

    /**
     * Replay operation has already completed and cannot be modified.
     */
    case ReplayAlreadyComplete = 'REPLAY_ALREADY_COMPLETE';

    /**
     * Replay operation was explicitly cancelled and will not complete.
     */
    case ReplayCancelled = 'REPLAY_CANCELLED';

    /**
     * Request was explicitly cancelled by the client before completion.
     */
    case Cancelled = 'CANCELLED';

    /**
     * Provided cancellation token is unknown or invalid.
     */
    case CancellationTokenUnknown = 'CANCELLATION_TOKEN_UNKNOWN';

    /**
     * Cancellation cannot be performed because operation has already completed.
     */
    case CancellationTooLate = 'CANCELLATION_TOO_LATE';

    /**
     * Unable to acquire lock immediately (no blocking requested).
     */
    case LockAcquisitionFailed = 'LOCK_ACQUISITION_FAILED';

    /**
     * Lock acquisition timed out during blocking wait.
     */
    case LockTimeout = 'LOCK_TIMEOUT';

    /**
     * Lock does not exist for release or status check.
     */
    case LockNotFound = 'LOCK_NOT_FOUND';

    /**
     * Lock release attempted with incorrect owner token.
     */
    case LockOwnershipMismatch = 'LOCK_OWNERSHIP_MISMATCH';

    /**
     * Lock was already released before release request.
     */
    case LockAlreadyReleased = 'LOCK_ALREADY_RELEASED';

    /**
     * Function does not support simulation mode.
     */
    case SimulationNotSupported = 'SIMULATION_NOT_SUPPORTED';

    /**
     * Requested simulation scenario does not exist.
     */
    case SimulationScenarioNotFound = 'SIMULATION_SCENARIO_NOT_FOUND';

    /**
     * Determine if this error condition is retryable.
     *
     * Retryable errors are typically transient conditions such as rate limiting,
     * temporary unavailability, or maintenance windows. Clients should implement
     * exponential backoff when retrying these errors. Non-retryable errors like
     * invalid requests or authentication failures will not succeed upon retry.
     *
     * @return bool True if the client should retry the request, false otherwise
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::FunctionDisabled,
            self::DeadlineExceeded,
            self::RateLimited,
            self::InternalError,
            self::Unavailable,
            self::DependencyError,
            self::IdempotencyProcessing,
            self::ServerMaintenance,
            self::FunctionMaintenance,
            self::LockAcquisitionFailed,
            self::LockTimeout => true,
            default => false,
        };
    }

    /**
     * Determine if this is a client-side error.
     *
     * Client errors indicate issues with the request itself, such as malformed
     * data, invalid arguments, missing authentication, or requests for non-existent
     * resources. These errors require client-side changes to resolve and should
     * not be retried without modification.
     *
     * @return bool True if the error originated from client request issues, false otherwise
     */
    public function isClient(): bool
    {
        return match ($this) {
            self::ParseError,
            self::InvalidRequest,
            self::InvalidProtocolVersion,
            self::FunctionNotFound,
            self::VersionNotFound,
            self::InvalidArguments,
            self::SchemaValidationFailed,
            self::ExtensionNotSupported,
            self::Unauthorized,
            self::Forbidden,
            self::NotFound,
            self::Conflict,
            self::Gone,
            self::IdempotencyConflict,
            self::AsyncOperationNotFound,
            self::AsyncOperationFailed,
            self::AsyncCannotCancel,
            self::LockNotFound,
            self::LockOwnershipMismatch,
            self::LockAlreadyReleased,
            self::SimulationNotSupported,
            self::SimulationScenarioNotFound => true,
            default => false,
        };
    }

    /**
     * Determine if this is a server-side error.
     *
     * Server errors indicate issues with the server implementation, infrastructure,
     * or external dependencies. These errors are not caused by client request issues
     * and may be retryable depending on the specific error condition. Monitoring
     * systems should alert on high rates of server errors.
     *
     * @return bool True if the error originated from server-side issues, false otherwise
     */
    public function isServer(): bool
    {
        return match ($this) {
            self::InternalError,
            self::Unavailable,
            self::DependencyError => true,
            default => false,
        };
    }

    /**
     * Determine if this is an authentication or authorization error.
     *
     * Authentication errors indicate issues with client credentials or permissions.
     * These errors require client-side action to provide valid credentials or
     * request appropriate permissions before retrying.
     *
     * @return bool True if this is an auth-related error, false otherwise
     */
    public function isAuthError(): bool
    {
        return match ($this) {
            self::Unauthorized,
            self::Forbidden => true,
            default => false,
        };
    }

    /**
     * Determine if this is a resource-related error.
     *
     * Resource errors indicate issues with locating or accessing specific
     * resources like functions, operations, or entities.
     *
     * @return bool True if this is a resource error, false otherwise
     */
    public function isResourceError(): bool
    {
        return match ($this) {
            self::FunctionNotFound,
            self::VersionNotFound,
            self::NotFound,
            self::Gone,
            self::AsyncOperationNotFound,
            self::ReplayNotFound,
            self::ReplayExpired,
            self::LockNotFound,
            self::SimulationScenarioNotFound,
            self::CancellationTokenUnknown => true,
            default => false,
        };
    }

    /**
     * Determine if this error indicates a maintenance or operational state.
     *
     * Maintenance errors indicate the service or function is temporarily
     * unavailable due to scheduled maintenance or operational concerns.
     *
     * @return bool True if this is a maintenance error, false otherwise
     */
    public function isMaintenanceError(): bool
    {
        return match ($this) {
            self::FunctionDisabled,
            self::ServerMaintenance,
            self::FunctionMaintenance,
            self::Unavailable => true,
            default => false,
        };
    }

    /**
     * Get the error category as a string for logging/metrics.
     *
     * Provides a consistent category label for error tracking, logging,
     * and metrics aggregation. Categories align with error code groupings.
     *
     * @return string Error category name (protocol, function, auth, resource, etc.)
     */
    public function getCategory(): string
    {
        return match ($this) {
            self::ParseError,
            self::InvalidRequest,
            self::InvalidProtocolVersion => 'protocol',

            self::FunctionNotFound,
            self::VersionNotFound,
            self::FunctionDisabled,
            self::InvalidArguments,
            self::SchemaValidationFailed,
            self::FunctionMaintenance => 'function',

            self::ExtensionNotSupported,
            self::ExtensionNotApplicable => 'extension',

            self::Unauthorized,
            self::Forbidden => 'authentication',

            self::NotFound,
            self::Conflict,
            self::Gone => 'resource',

            self::DeadlineExceeded,
            self::RateLimited => 'rate_limiting',

            self::InternalError,
            self::Unavailable,
            self::DependencyError,
            self::ServerMaintenance => 'server',

            self::IdempotencyConflict,
            self::IdempotencyProcessing => 'idempotency',

            self::AsyncOperationNotFound,
            self::AsyncOperationFailed,
            self::AsyncCannotCancel => 'async',

            self::ReplayNotFound,
            self::ReplayExpired,
            self::ReplayAlreadyComplete,
            self::ReplayCancelled => 'replay',

            self::Cancelled,
            self::CancellationTokenUnknown,
            self::CancellationTooLate => 'cancellation',

            self::LockAcquisitionFailed,
            self::LockTimeout,
            self::LockNotFound,
            self::LockOwnershipMismatch,
            self::LockAlreadyReleased => 'locking',

            self::SimulationNotSupported,
            self::SimulationScenarioNotFound => 'simulation',
        };
    }

    /**
     * Create a standardized error response array.
     *
     * Generates a consistent error response structure matching the Forrst
     * protocol specification. Includes the error code, HTTP status, and
     * optional message and metadata.
     *
     * @param string $message Human-readable error message
     * @param array<string, mixed> $metadata Additional error context/metadata
     * @return array{code: string, status: int, message: string, metadata: array<string, mixed>}
     */
    public function toErrorResponse(string $message, array $metadata = []): array
    {
        return [
            'code' => $this->value,
            'status' => $this->toStatusCode(),
            'message' => $message,
            'metadata' => $metadata,
        ];
    }

    /**
     * Convert the error code to an HTTP status code.
     *
     * Maps protocol error codes to their corresponding HTTP status codes for
     * transport-layer error reporting. This enables proper HTTP semantics when
     * the Forrst protocol is used over HTTP/HTTPS transports.
     *
     * @return int HTTP status code (400-599 range) corresponding to this error
     */
    public function toStatusCode(): int
    {
        return match ($this) {
            self::ParseError => 400,
            self::InvalidRequest => 400,
            self::InvalidProtocolVersion => 400,
            self::FunctionNotFound => 404,
            self::VersionNotFound => 404,
            self::FunctionDisabled => 503,
            self::InvalidArguments => 400,
            self::SchemaValidationFailed => 422,
            self::ExtensionNotSupported => 400,
            self::ExtensionNotApplicable => 400,
            self::Unauthorized => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::Conflict => 409,
            self::Gone => 410,
            self::DeadlineExceeded => 504,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::Unavailable => 503,
            self::DependencyError => 502,
            self::IdempotencyConflict => 409,
            self::IdempotencyProcessing => 409,
            self::AsyncOperationNotFound => 404,
            self::AsyncOperationFailed => 500,
            self::AsyncCannotCancel => 400,
            self::ServerMaintenance => 503,
            self::FunctionMaintenance => 503,
            self::ReplayNotFound => 404,
            self::ReplayExpired => 410,
            self::ReplayAlreadyComplete => 409,
            self::ReplayCancelled => 410,
            self::Cancelled => 499,
            self::CancellationTokenUnknown => 404,
            self::CancellationTooLate => 409,
            self::LockAcquisitionFailed => 423,
            self::LockTimeout => 423,
            self::LockNotFound => 404,
            self::LockOwnershipMismatch => 403,
            self::LockAlreadyReleased => 409,
            self::SimulationNotSupported => 400,
            self::SimulationScenarioNotFound => 404,
        };
    }
}

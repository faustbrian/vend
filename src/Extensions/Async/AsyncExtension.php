<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Async;

use Cline\Forrst\Contracts\FunctionInterface;
use Cline\Forrst\Contracts\OperationRepositoryInterface;
use Cline\Forrst\Contracts\ProvidesFunctionsInterface;
use Cline\Forrst\Data\ErrorData;
use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Cline\Forrst\Exceptions\DataTransformationException;
use Cline\Forrst\Exceptions\FieldExceedsMaxLengthException;
use Cline\Forrst\Exceptions\InvalidFieldTypeException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\OperationQuotaExceededException;
use Cline\Forrst\Extensions\AbstractExtension;
use Cline\Forrst\Extensions\Async\Exceptions\InvalidOperationStateException;
use Cline\Forrst\Extensions\Async\Exceptions\OperationNotFoundException;
use Cline\Forrst\Extensions\Async\Functions\OperationCancelFunction;
use Cline\Forrst\Extensions\Async\Functions\OperationListFunction;
use Cline\Forrst\Extensions\Async\Functions\OperationStatusFunction;
use Cline\Forrst\Extensions\ExtensionUrn;
use Cline\Forrst\Functions\FunctionUrn;
use InvalidArgumentException;
use Override;
use RuntimeException;

use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;

use function array_merge;
use function bin2hex;
use function ctype_xdigit;
use function filter_var;
use function in_array;
use function is_string;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function min;
use function now;
use function parse_url;
use function random_bytes;
use function sprintf;
use function str_starts_with;

/**
 * Async operations extension handler.
 *
 * Enables long-running function execution by decoupling request initiation from
 * result delivery. Functions return immediately with an operation ID that clients
 * poll for completion, preventing timeout issues for expensive operations.
 *
 * For webhook notifications when operations complete, use the WebhookExtension
 * alongside this extension. The webhook extension handles registration and dispatch
 * of Standard Webhooks-compliant callbacks.
 *
 * Request options:
 * - preferred: boolean - client prefers async execution if supported
 *
 * Response data:
 * - operation_id: unique identifier for tracking operation status
 * - status: current state (pending, processing, completed, failed, cancelled)
 * - poll: function call specification for status checks
 * - retry_after: suggested wait duration before next poll
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/async
 * @see https://docs.cline.sh/forrst/extensions/webhook
 */
final class AsyncExtension extends AbstractExtension implements ProvidesFunctionsInterface
{
    /**
     * Default retry interval in seconds for polling.
     */
    private const int DEFAULT_RETRY_SECONDS = 5;

    /**
     * Allowed URL schemes for callback URLs (HTTPS only for security).
     */
    private const array ALLOWED_CALLBACK_SCHEMES = ['https'];

    /**
     * Blocked hosts to prevent SSRF attacks.
     */
    private const array BLOCKED_CALLBACK_HOSTS = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '169.254.169.254', // AWS metadata endpoint
        '::1',
        'metadata.google.internal', // GCP metadata
    ];

    /**
     * Maximum allowed callback URL length to prevent DoS.
     */
    private const int MAX_CALLBACK_URL_LENGTH = 2_048;

    /**
     * Maximum metadata size in bytes (64KB) to prevent storage bloat and DoS.
     */
    private const int MAX_METADATA_SIZE_BYTES = 65_536;

    /**
     * Number of random bytes for operation ID (96 bits of entropy).
     *
     * Provides 2^96 possible IDs (~7.9 × 10^28), making collisions
     * astronomically unlikely even with billions of operations.
     */
    private const int OPERATION_ID_BYTES = 12;

    /**
     * Prefix for operation IDs.
     */
    private const string OPERATION_ID_PREFIX = 'op_';

    /**
     * Expected length of operation ID string (prefix + hex bytes).
     */
    private const int OPERATION_ID_LENGTH = 27; // 'op_' (3 chars) + 24 hex chars (12 bytes)

    /**
     * Default TTL for operations in seconds (24 hours).
     *
     * After this duration, operations can be safely purged from storage.
     * Prevents unlimited storage growth and ensures compliance with data retention policies.
     */
    private const int DEFAULT_OPERATION_TTL_SECONDS = 86_400;

    /**
     * Maximum allowed TTL for operations in seconds (7 days).
     *
     * Limits how long operations can be retained to prevent storage bloat
     * and ensure timely cleanup of stale data.
     */
    private const int MAX_OPERATION_TTL_SECONDS = 604_800;

    /**
     * Minimum allowed TTL for operations in seconds (1 minute).
     *
     * Ensures operations remain available long enough for clients to poll results.
     */
    private const int MIN_OPERATION_TTL_SECONDS = 60;

    /**
     * Maximum concurrent active operations per user.
     *
     * Prevents resource exhaustion by limiting how many pending/processing
     * operations a single user can have at once.
     */
    private const int MAX_CONCURRENT_OPERATIONS = 10;

    /**
     * Create a new async extension instance.
     *
     * @param OperationRepositoryInterface $operations Repository for persisting and retrieving
     *                                                 async operation state across polling requests.
     *                                                 Implementations must support concurrent access
     *                                                 and atomic updates for distributed deployments.
     */
    public function __construct(
        private readonly OperationRepositoryInterface $operations,
    ) {}

    /**
     * Get the functions provided by this extension.
     *
     * Returns the operation management functions (status, cancel, list) that are
     * automatically registered when the async extension is enabled on a server.
     *
     * @return array<int, class-string<FunctionInterface>> Function class names
     */
    #[Override()]
    public function functions(): array
    {
        return [
            OperationStatusFunction::class,
            OperationCancelFunction::class,
            OperationListFunction::class,
        ];
    }

    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::Async->value;
    }

    #[Override()]
    public function isErrorFatal(): bool
    {
        return true;
    }

    /**
     * Check if client prefers async execution.
     *
     * Function handlers should check this flag to decide between sync and async
     * execution. The server is not obligated to honor the preference but should
     * use it as a hint when the operation supports both modes.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return bool True if client indicated async preference
     */
    public function isPreferred(?array $options): bool
    {
        return ($options['preferred'] ?? false) === true;
    }

    /**
     * Get callback URL for completion notification.
     *
     * If provided, the server should POST the operation result to this URL when
     * execution completes, allowing clients to avoid polling for long operations.
     *
     * Validates URL format, enforces HTTPS, and blocks internal/private IPs to
     * prevent SSRF (Server-Side Request Forgery) attacks.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @throws InvalidArgumentException If callback URL is invalid or blocked
     * @return null|string              Callback URL or null if not specified
     */
    public function getCallbackUrl(?array $options): ?string
    {
        $callbackUrl = $options['callback_url'] ?? null;

        if ($callbackUrl === null) {
            return null;
        }

        if (!is_string($callbackUrl)) {
            throw InvalidFieldTypeException::forField('callback_url', 'string', $callbackUrl);
        }

        // Validate max URL length (prevent DoS)
        if (mb_strlen($callbackUrl) > self::MAX_CALLBACK_URL_LENGTH) {
            throw FieldExceedsMaxLengthException::forField('callback_url', self::MAX_CALLBACK_URL_LENGTH);
        }

        // Validate URL format
        $parts = parse_url($callbackUrl);

        if ($parts === false) {
            throw InvalidFieldValueException::forField('callback_url', 'Invalid URL format');
        }

        // Enforce HTTPS only
        if (!isset($parts['scheme']) || !in_array($parts['scheme'], self::ALLOWED_CALLBACK_SCHEMES, true)) {
            throw InvalidFieldValueException::forField(
                'callback_url',
                sprintf('Must use HTTPS scheme, got: %s', $parts['scheme'] ?? 'none'),
            );
        }

        // Block internal/private IPs (SSRF protection)
        $host = $parts['host'] ?? '';

        if (in_array(mb_strtolower($host), self::BLOCKED_CALLBACK_HOSTS, true)) {
            throw InvalidFieldValueException::forField(
                'callback_url',
                sprintf('Host is not allowed: %s', $host),
            );
        }

        // Block private IP ranges
        if (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw InvalidFieldValueException::forField(
                'callback_url',
                sprintf('Cannot use private/reserved IP addresses: %s', $host),
            );
        }

        return $callbackUrl;
    }

    /**
     * Create an async operation and build immediate response.
     *
     * Function handlers call this method when deciding to execute asynchronously.
     * It creates a pending operation record, persists it to the repository, and
     * returns both the immediate response to send to the client and the operation
     * record for background processing.
     *
     * The response includes polling instructions and retry timing to optimize
     * client polling behavior.
     *
     * @param RequestObjectData         $request      Original function call request
     * @param ExtensionData             $extension    Async extension data from request
     * @param null|array<string, mixed> $metadata     Optional metadata stored with operation
     * @param int                       $retrySeconds Suggested seconds between poll attempts
     * @param null|int                  $ttlSeconds   Time-to-live in seconds (clamped to min/max bounds)
     * @param null|string               $ownerId      Owner ID for access control (required for multi-user systems)
     *
     * @throws InvalidArgumentException                                If metadata size exceeds maximum allowed (64KB)
     * @throws OperationQuotaExceededException                         If user has too many active operations
     * @return array{response: ResponseData, operation: OperationData} Tuple of response and operation
     */
    public function createAsyncOperation(
        RequestObjectData $request,
        ExtensionData $extension,
        ?array $metadata = null,
        int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
        ?int $ttlSeconds = null,
        ?string $ownerId = null,
    ): array {
        // Check concurrent operation limit for authenticated users
        if ($ownerId !== null) {
            $activeCount = $this->operations->countActiveByOwner($ownerId);

            if ($activeCount >= self::MAX_CONCURRENT_OPERATIONS) {
                throw OperationQuotaExceededException::create($activeCount, self::MAX_CONCURRENT_OPERATIONS);
            }
        }

        // Validate and clamp TTL to allowed bounds
        $ttl = $ttlSeconds ?? self::DEFAULT_OPERATION_TTL_SECONDS;
        $ttl = max(self::MIN_OPERATION_TTL_SECONDS, min($ttl, self::MAX_OPERATION_TTL_SECONDS));

        $expiresAt = now()->addSeconds($ttl)->toImmutable();

        // Validate metadata size
        if ($metadata !== null) {
            $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
            $metadataSize = mb_strlen($metadataJson);

            if ($metadataSize > self::MAX_METADATA_SIZE_BYTES) {
                throw InvalidFieldValueException::forField(
                    'metadata',
                    sprintf('Size (%d bytes) exceeds maximum allowed (%d bytes)', $metadataSize, self::MAX_METADATA_SIZE_BYTES),
                );
            }
        }

        $systemMetadata = [
            'original_request_id' => $request->id,
            'callback_url' => $this->getCallbackUrl($extension->options),
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'ttl_seconds' => $ttl,
            'owner_id' => $ownerId,
        ];

        $finalMetadata = $metadata !== null
            ? array_merge($metadata, $systemMetadata)
            : $systemMetadata;

        // Create the operation record
        $operation = new OperationData(
            id: $this->generateOperationId(),
            function: $request->call->function,
            version: $request->call->version,
            status: OperationStatus::Pending,
            metadata: $finalMetadata,
        );

        // Persist the operation with owner association
        $this->operations->save($operation, $ownerId);

        // Build the async response
        $response = ResponseData::success(
            result: null,
            id: $request->id,
            extensions: [
                ExtensionData::response(ExtensionUrn::Async->value, [
                    'operation_id' => $operation->id,
                    'status' => $operation->status,
                    'poll' => [
                        'function' => FunctionUrn::OperationStatus->value,
                        'version' => '1',
                        'arguments' => ['operation_id' => $operation->id],
                    ],
                    'retry_after' => [
                        'value' => $retrySeconds,
                        'unit' => 'second',
                    ],
                ]),
            ],
        );

        return [
            'response' => $response,
            'operation' => $operation,
        ];
    }

    /**
     * Transition operation to processing status.
     *
     * Background workers should call this when beginning execution to signal
     * to polling clients that work has started.
     *
     * @param string     $operationId Unique operation identifier
     * @param null|float $progress    Optional initial progress value (0.0 to 1.0)
     *
     * @throws InvalidArgumentException       If operation ID format is invalid
     * @throws InvalidOperationStateException If operation cannot be marked as processing
     * @throws OperationNotFoundException     If operation doesn't exist
     */
    public function markProcessing(string $operationId, ?float $progress = null): void
    {
        $this->validateOperationId($operationId);

        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            throw OperationNotFoundException::forOperation($operationId, 'mark as processing');
        }

        // Validate state transitions
        if ($operation->status === OperationStatus::Completed) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'mark as processing', $operation->status);
        }

        if ($operation->status === OperationStatus::Failed) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'mark as processing', $operation->status);
        }

        if ($operation->status === OperationStatus::Cancelled) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'mark as processing', $operation->status);
        }

        $updated = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: OperationStatus::Processing,
            progress: $progress,
            startedAt: now()->toImmutable(),
            metadata: $operation->metadata,
        );

        $this->operations->save($updated);
    }

    /**
     * Complete operation with successful result.
     *
     * Background workers call this when execution finishes successfully.
     * The result is stored and subsequent polling requests will receive it.
     *
     * @param string $operationId Unique operation identifier
     * @param mixed  $result      Function execution result to return to client
     *
     * @throws InvalidArgumentException       If operation ID format is invalid
     * @throws InvalidOperationStateException If operation cannot be completed
     * @throws OperationNotFoundException     If operation doesn't exist
     */
    public function complete(string $operationId, mixed $result): void
    {
        $this->validateOperationId($operationId);

        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            throw OperationNotFoundException::forOperation($operationId, 'complete');
        }

        // Validate state transitions
        if ($operation->status === OperationStatus::Completed) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'complete', $operation->status);
        }

        if ($operation->status === OperationStatus::Cancelled) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'complete', $operation->status);
        }

        $updated = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: OperationStatus::Completed,
            progress: 1.0,
            result: $result,
            startedAt: $operation->startedAt,
            completedAt: now()->toImmutable(),
            metadata: $operation->metadata,
        );

        $this->operations->save($updated);
    }

    /**
     * Fail operation with error details.
     *
     * Background workers call this when execution encounters unrecoverable errors.
     * The errors are stored and subsequent polling requests will receive them.
     *
     * @param string                $operationId Unique operation identifier
     * @param array<int, ErrorData> $errors      Error details describing the failure
     *
     * @throws InvalidArgumentException       If operation ID format is invalid
     * @throws InvalidOperationStateException If operation cannot be failed
     * @throws OperationNotFoundException     If operation doesn't exist
     */
    public function fail(string $operationId, array $errors): void
    {
        $this->validateOperationId($operationId);

        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            throw OperationNotFoundException::forOperation($operationId, 'fail');
        }

        // Validate state transitions
        if ($operation->status === OperationStatus::Completed) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'fail', $operation->status);
        }

        if ($operation->status === OperationStatus::Failed) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'fail', $operation->status);
        }

        if ($operation->status === OperationStatus::Cancelled) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'fail', $operation->status);
        }

        $updated = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: OperationStatus::Failed,
            progress: $operation->progress,
            errors: $errors,
            startedAt: $operation->startedAt,
            completedAt: now()->toImmutable(),
            metadata: $operation->metadata,
        );

        $this->operations->save($updated);
    }

    /**
     * Update operation progress for long-running tasks.
     *
     * Background workers can call this periodically during execution to provide
     * progress feedback to polling clients. Progress is clamped to [0.0, 1.0]
     * and cannot decrease from its current value.
     *
     * @param string      $operationId Unique operation identifier
     * @param float       $progress    Progress value between 0.0 (started) and 1.0 (complete)
     * @param null|string $message     Optional human-readable status message
     *
     * @throws InvalidArgumentException       If operation ID format is invalid, progress decreases, or message exceeds maximum length
     * @throws InvalidOperationStateException If operation cannot have progress updated
     * @throws OperationNotFoundException     If operation doesn't exist
     */
    public function updateProgress(string $operationId, float $progress, ?string $message = null): void
    {
        $this->validateOperationId($operationId);

        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            throw OperationNotFoundException::forOperation($operationId, 'update progress');
        }

        // Validate operation is in a state where progress updates make sense
        if (!in_array($operation->status, [OperationStatus::Pending, OperationStatus::Processing], true)) {
            throw InvalidOperationStateException::cannotTransition($operationId, 'update progress', $operation->status);
        }

        // Validate progress doesn't decrease
        $currentProgress = $operation->progress ?? 0.0;
        $newProgress = max(0.0, min(1.0, $progress));

        if ($newProgress < $currentProgress) {
            throw InvalidFieldValueException::forField(
                'progress',
                sprintf('Cannot decrease from %.2f to %.2f for operation %s', $currentProgress, $newProgress, $operationId),
            );
        }

        $metadata = $operation->metadata ?? [];

        if ($message !== null) {
            if (mb_strlen($message) > 1_000) {
                throw FieldExceedsMaxLengthException::forField('message', 1_000);
            }

            $metadata['progress_message'] = $message;
            $metadata['progress_updated_at'] = now()->toIso8601String();
        }

        $updated = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: $operation->status,
            progress: $newProgress,
            result: $operation->result,
            errors: $operation->errors,
            startedAt: $operation->startedAt,
            completedAt: $operation->completedAt,
            cancelledAt: $operation->cancelledAt,
            metadata: $metadata,
        );

        $this->operations->save($updated);
    }

    /**
     * Validate operation ID format.
     *
     * Ensures the operation ID has the correct length, prefix, and contains
     * only valid hexadecimal characters. This prevents injection attacks and
     * invalid lookups.
     *
     * @param string $operationId Operation identifier to validate
     *
     * @throws InvalidArgumentException If operation ID format is invalid
     */
    private function validateOperationId(string $operationId): void
    {
        if (mb_strlen($operationId) !== self::OPERATION_ID_LENGTH) {
            throw InvalidFieldValueException::forField(
                'operation_id',
                sprintf('Invalid length: expected %d, got %d', self::OPERATION_ID_LENGTH, mb_strlen($operationId)),
            );
        }

        if (!str_starts_with($operationId, self::OPERATION_ID_PREFIX)) {
            throw InvalidFieldValueException::forField(
                'operation_id',
                sprintf('Invalid prefix: expected "%s"', self::OPERATION_ID_PREFIX),
            );
        }

        $hex = mb_substr($operationId, mb_strlen(self::OPERATION_ID_PREFIX));

        if (!ctype_xdigit($hex)) {
            throw InvalidFieldValueException::forField(
                'operation_id',
                'Must contain only hexadecimal characters after prefix',
            );
        }
    }

    /**
     * Generate cryptographically unique operation ID.
     *
     * Uses 12 random bytes (96 bits) encoded as hex, providing sufficient
     * uniqueness for distributed operation tracking without coordination.
     * Checks for collisions and retries if necessary.
     *
     * @throws RuntimeException If unable to generate unique ID after maximum attempts
     * @return string           Operation identifier with 'op_' prefix
     */
    private function generateOperationId(): string
    {
        $maxAttempts = 10;

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $operationId = 'op_'.bin2hex(random_bytes(self::OPERATION_ID_BYTES));

            // Check if ID already exists
            $existing = $this->operations->find($operationId);

            if (!$existing instanceof OperationData) {
                return $operationId;
            }

            // Collision detected (extremely rare), try again
        }

        throw DataTransformationException::cannotTransform('', 'operation ID', sprintf('exhausted %d unique ID generation attempts', $maxAttempts));
    }
}

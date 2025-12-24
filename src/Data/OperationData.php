<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data;

use Carbon\CarbonImmutable;
use Cline\Forrst\Exceptions\EmptyFieldException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\MissingRequiredFieldException;
use Override;

use function array_map;
use function assert;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_trim;
use function preg_match;
use function sprintf;

/**
 * Represents an async operation.
 *
 * Tracks the state and progress of asynchronous function calls through their
 * lifecycle from pending to completion or failure. Operations can be queried
 * to check status, retrieve results, or cancel execution.
 *
 * Each operation maintains timing information, progress indicators, and either
 * a result (on success) or error details (on failure). The status field follows
 * a state machine pattern where terminal states (completed, failed, cancelled)
 * cannot transition to other states.
 *
 * @author Brian Faust <brian@cline.sh>
 * @see https://docs.cline.sh/forrst/extensions/async
 */
final class OperationData extends AbstractData
{
    /**
     * Create a new operation data instance.
     *
     * @param string                     $id          Unique operation identifier (UUID or similar).
     *                                                Used to query operation status and retrieve
     *                                                results. Must be globally unique across all
     *                                                operations in the system.
     * @param string                     $function    Function name that was invoked to create this
     *                                                operation. Uses the same dot notation as the
     *                                                call data (e.g., "orders.process").
     * @param null|string                $version     Optional function version that was called.
     *                                                Records which API version initiated the operation.
     * @param OperationStatus            $status      Current operation status in the lifecycle.
     *                                                Starts as Pending, transitions through Processing,
     *                                                and ends in Completed, Failed, or Cancelled.
     * @param null|float                 $progress    Optional progress indicator as decimal 0.0-1.0.
     *                                                Allows clients to display progress bars or
     *                                                estimates. Null indicates progress unavailable.
     * @param mixed                      $result      Operation result data when status is Completed.
     *                                                Null for non-completed operations. Structure
     *                                                matches the function's return type.
     * @param null|array<int, ErrorData> $errors      Array of errors when status is Failed. Each
     *                                                error includes code, message, and details.
     *                                                Null for non-failed operations.
     * @param null|CarbonImmutable       $startedAt   Timestamp when operation execution began.
     *                                                Null if operation is still pending.
     * @param null|CarbonImmutable       $completedAt Timestamp when operation finished successfully.
     *                                                Null unless status is Completed.
     * @param null|CarbonImmutable       $cancelledAt Timestamp when operation was cancelled.
     *                                                Null unless status is Cancelled.
     * @param null|array<string, mixed>  $metadata    Optional additional operation metadata such as
     *                                                retry counts, queue position, or custom tracking
     *                                                information specific to the application.
     * @param int                        $lockVersion Optimistic locking version for concurrent access
     *                                                control. Incremented on each save. Used to detect
     *                                                concurrent modifications and prevent race conditions.
     */
    public function __construct(
        /** @var string */
        public readonly string $id,
        /** @var string */
        public readonly string $function,
        public readonly ?string $version = null,
        /** @var OperationStatus */
        public readonly OperationStatus $status = OperationStatus::Pending,
        /** @var null|float */
        public readonly ?float $progress = null,
        public readonly mixed $result = null,
        public readonly ?array $errors = null,
        /** @var null|CarbonImmutable */
        public readonly ?CarbonImmutable $startedAt = null,
        /** @var null|CarbonImmutable */
        public readonly ?CarbonImmutable $completedAt = null,
        /** @var null|CarbonImmutable */
        public readonly ?CarbonImmutable $cancelledAt = null,
        public readonly ?array $metadata = null,
        public readonly int $lockVersion = 1,
    ) {
        // Validate required fields
        if (mb_trim($id) === '') {
            throw EmptyFieldException::forField('id');
        }

        if (mb_trim($function) === '') {
            throw EmptyFieldException::forField('function');
        }

        // Validate ID format: UUID, ULID, or prefixed format (op_/op- prefix)
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);
        $isUlid = (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id);
        $isPrefixed = (bool) preg_match('/^op[_-][0-9a-z]+$/i', $id);

        if (!$isUuid && !$isUlid && !$isPrefixed) {
            throw InvalidFieldValueException::forField(
                'id',
                sprintf('Operation ID must be a valid UUID, ULID, or prefixed format (op_/op- followed by alphanumeric), got: %s', $id),
            );
        }

        // Validate progress bounds
        if ($progress !== null && ($progress < 0.0 || $progress > 1.0)) {
            throw InvalidFieldValueException::forField(
                'progress',
                sprintf('Operation progress must be between 0.0 and 1.0, got: %.2f', $progress),
            );
        }

        // Validate state-timestamp consistency
        if ($status === OperationStatus::Completed && !$completedAt instanceof CarbonImmutable) {
            throw MissingRequiredFieldException::forField('completedAt');
        }

        if ($status === OperationStatus::Failed && $errors === null) {
            throw MissingRequiredFieldException::forField('errors');
        }

        if ($status === OperationStatus::Cancelled && !$cancelledAt instanceof CarbonImmutable) {
            throw MissingRequiredFieldException::forField('cancelledAt');
        }

        if ($status === OperationStatus::Processing && !$startedAt instanceof CarbonImmutable) {
            throw MissingRequiredFieldException::forField('startedAt');
        }

        // Validate timestamp logical ordering
        if ($startedAt instanceof CarbonImmutable && $completedAt instanceof CarbonImmutable && $completedAt->lt($startedAt)) {
            throw InvalidFieldValueException::forField(
                'completedAt',
                'Operation completedAt cannot be before startedAt',
            );
        }

        if ($startedAt instanceof CarbonImmutable && $cancelledAt instanceof CarbonImmutable && $cancelledAt->lt($startedAt)) {
            throw InvalidFieldValueException::forField(
                'cancelledAt',
                'Operation cancelledAt cannot be before startedAt',
            );
        }
    }

    /**
     * Create from array representation.
     *
     * Deserializes operation data from array format, reconstructing nested
     * ErrorData objects and parsing ISO8601 timestamps into CarbonImmutable
     * instances. Handles both single array and Spatie Data variadic patterns.
     *
     * @param array<string, mixed> ...$payloads Array data to deserialize
     *
     * @return static OperationData instance
     */
    public static function from(mixed ...$payloads): static
    {
        // Handle both single array and Spatie Data's variadic arguments
        $payload = $payloads[0] ?? [];

        // Type assertion: ensure we have an array to work with
        /** @phpstan-ignore-next-line function.alreadyNarrowedType */
        assert(is_array($payload));

        /** @var array<string, mixed> $data */
        $data = $payload;

        $errors = null;

        if (isset($data['errors']) && is_array($data['errors'])) {
            /** @var array<int, ErrorData> $errorArray */
            $errorArray = [];

            foreach ($data['errors'] as $errorData) {
                if (!is_array($errorData)) {
                    continue;
                }

                /** @var array<string, mixed> $errorData */
                $errorArray[] = ErrorData::from($errorData);
            }

            $errors = $errorArray;
        }

        $id = $data['id'] ?? '';
        assert(is_string($id) || is_int($id));
        $idString = (string) $id;

        $function = $data['function'] ?? '';
        assert(is_string($function));

        $version = null;

        if (isset($data['version'])) {
            assert(is_string($data['version']));
            $version = $data['version'];
        }

        $statusValue = $data['status'] ?? '';
        assert(is_string($statusValue) || is_int($statusValue));
        $status = OperationStatus::tryFrom($statusValue) ?? OperationStatus::Pending;

        $metadata = null;

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            /** @var array<string, mixed> $metadata */
            $metadata = $data['metadata'];
        }

        $lockVersion = 1;

        if (isset($data['lock_version']) && is_int($data['lock_version'])) {
            $lockVersion = $data['lock_version'];
        }

        return new self(
            id: $idString,
            function: $function,
            version: $version,
            status: $status,
            progress: isset($data['progress']) && is_numeric($data['progress']) ? (float) $data['progress'] : null,
            result: $data['result'] ?? null,
            errors: $errors,
            startedAt: isset($data['started_at']) && (is_string($data['started_at']) || is_int($data['started_at'])) ? CarbonImmutable::parse($data['started_at']) : null,
            completedAt: isset($data['completed_at']) && (is_string($data['completed_at']) || is_int($data['completed_at'])) ? CarbonImmutable::parse($data['completed_at']) : null,
            cancelledAt: isset($data['cancelled_at']) && (is_string($data['cancelled_at']) || is_int($data['cancelled_at'])) ? CarbonImmutable::parse($data['cancelled_at']) : null,
            metadata: $metadata,
            lockVersion: $lockVersion,
        );
    }

    /**
     * Check if the operation is pending.
     *
     * @return bool True when operation is queued but not yet executing
     */
    public function isPending(): bool
    {
        return $this->status === OperationStatus::Pending;
    }

    /**
     * Check if the operation is processing.
     *
     * @return bool True when operation is actively executing
     */
    public function isProcessing(): bool
    {
        return $this->status === OperationStatus::Processing;
    }

    /**
     * Check if the operation is completed.
     *
     * @return bool True when operation finished successfully with a result
     */
    public function isCompleted(): bool
    {
        return $this->status === OperationStatus::Completed;
    }

    /**
     * Check if the operation failed.
     *
     * @return bool True when operation encountered errors and terminated
     */
    public function isFailed(): bool
    {
        return $this->status === OperationStatus::Failed;
    }

    /**
     * Check if the operation was cancelled.
     *
     * @return bool True when operation was cancelled before completion
     */
    public function isCancelled(): bool
    {
        return $this->status === OperationStatus::Cancelled;
    }

    /**
     * Check if the operation is still in progress.
     *
     * Returns true for pending or processing states, indicating the
     * operation may transition to a terminal state.
     *
     * @return bool True when operation is pending or processing
     */
    public function isInProgress(): bool
    {
        return $this->status->isInProgress();
    }

    /**
     * Check if the operation is terminal (cannot change).
     *
     * Terminal states are completed, failed, or cancelled. Once an
     * operation reaches a terminal state, it cannot transition further.
     *
     * @return bool True when operation is in a terminal state
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Convert to array representation.
     *
     * Serializes the operation including all non-null fields. Timestamps
     * are converted to ISO8601 strings, and nested ErrorData objects are
     * converted to arrays. Follows the null omission convention.
     *
     * @return array<string, mixed> Array representation of the operation
     */
    #[Override()]
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'function' => $this->function,
            'status' => $this->status->value,
        ];

        if ($this->version !== null) {
            $result['version'] = $this->version;
        }

        if ($this->progress !== null) {
            $result['progress'] = $this->progress;
        }

        if ($this->result !== null) {
            $result['result'] = $this->result;
        }

        if ($this->errors !== null) {
            $result['errors'] = array_map(
                fn (ErrorData $err): array => $err->toArray(),
                $this->errors,
            );
        }

        if ($this->startedAt instanceof CarbonImmutable) {
            $result['started_at'] = $this->startedAt->toIso8601String();
        }

        if ($this->completedAt instanceof CarbonImmutable) {
            $result['completed_at'] = $this->completedAt->toIso8601String();
        }

        if ($this->cancelledAt instanceof CarbonImmutable) {
            $result['cancelled_at'] = $this->cancelledAt->toIso8601String();
        }

        if ($this->metadata !== null) {
            $result['metadata'] = $this->metadata;
        }

        $result['lock_version'] = $this->lockVersion;

        return $result;
    }
}

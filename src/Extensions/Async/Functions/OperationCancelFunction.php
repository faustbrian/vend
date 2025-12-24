<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Async\Functions;

use Carbon\CarbonImmutable;
use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Contracts\OperationRepositoryInterface;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Data\OperationStatus;
use Cline\Forrst\Exceptions\InvalidFieldTypeException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\OperationCannotCancelException;
use Cline\Forrst\Exceptions\OperationNotFoundException;
use Cline\Forrst\Exceptions\OperationVersionConflictException;
use Cline\Forrst\Extensions\Async\Descriptors\OperationCancelDescriptor;
use Cline\Forrst\Functions\AbstractFunction;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function is_string;
use function preg_match;

/**
 * Async operation cancellation function.
 *
 * Implements forrst.operation.cancel for cancelling pending operations.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/async
 */
#[Descriptor(OperationCancelDescriptor::class)]
final class OperationCancelFunction extends AbstractFunction
{
    /**
     * Maximum retry attempts for optimistic locking conflicts.
     */
    private const int MAX_RETRIES = 3;

    /**
     * Create a new operation cancel function instance.
     *
     * @param OperationRepositoryInterface $repository Operation repository
     * @param LoggerInterface              $logger     Logger for recording operations
     */
    public function __construct(
        private readonly OperationRepositoryInterface $repository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Execute the operation cancel function.
     *
     * Operations are scoped to the authenticated user. Users can only cancel
     * operations they own. Returns "not found" for both missing and unauthorized
     * operations to prevent enumeration attacks.
     *
     * Uses optimistic locking to prevent race conditions when multiple processes
     * attempt to cancel the same operation simultaneously.
     *
     * @throws OperationCannotCancelException    If the operation is in a terminal state
     * @throws OperationNotFoundException        If the operation does not exist or user is unauthorized
     * @throws OperationVersionConflictException If max retries exceeded due to concurrent modifications
     *
     * @return array{operation_id: string, status: string, cancelled_at: string} Cancellation result
     */
    public function __invoke(): array
    {
        $operationId = $this->requestObject->getArgument('operation_id');

        if (!is_string($operationId)) {
            throw InvalidFieldTypeException::forField('operation_id', 'string', $operationId);
        }

        $this->validateOperationId($operationId);

        // Get authenticated user for access control
        $userId = $this->getAuthenticatedUserId();

        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            // Find operation with access control - returns null if not found OR unauthorized
            $operation = $this->repository->find($operationId, $userId);

            if (!$operation instanceof OperationData) {
                $this->logger->warning('Operation not found or unauthorized for cancellation', [
                    'operation_id' => $operationId,
                    'user_id' => $userId,
                ]);

                // Generic error to prevent enumeration attacks
                throw OperationNotFoundException::create($operationId);
            }

            if ($operation->isTerminal()) {
                $this->logger->info('Cannot cancel terminal operation', [
                    'operation_id' => $operationId,
                    'status' => $operation->status->value,
                ]);

                throw OperationCannotCancelException::create($operationId, $operation->status);
            }

            $now = CarbonImmutable::now();
            $expectedVersion = $operation->lockVersion;

            $cancelledOperation = new OperationData(
                id: $operation->id,
                function: $operation->function,
                version: $operation->version,
                status: OperationStatus::Cancelled,
                progress: $operation->progress,
                result: $operation->result,
                errors: $operation->errors,
                startedAt: $operation->startedAt,
                completedAt: $operation->completedAt,
                cancelledAt: $now,
                metadata: $operation->metadata,
                lockVersion: $expectedVersion + 1,
            );

            // Save with optimistic locking - returns false if version mismatch
            $saved = $this->repository->saveIfVersionMatches(
                $cancelledOperation,
                $expectedVersion,
                $userId,
            );

            if ($saved) {
                $this->logger->info('Operation cancelled successfully', [
                    'operation_id' => $operationId,
                    'function' => $operation->function,
                    'user_id' => $userId,
                ]);

                return [
                    'operation_id' => $operationId,
                    'status' => 'cancelled',
                    'cancelled_at' => $now->toIso8601String(),
                ];
            }

            ++$retries;
            $this->logger->debug('Optimistic lock conflict, retrying', [
                'operation_id' => $operationId,
                'attempt' => $retries,
                'max_retries' => self::MAX_RETRIES,
            ]);
        }

        // Max retries exceeded
        $this->logger->warning('Operation cancellation failed after max retries', [
            'operation_id' => $operationId,
            'retries' => self::MAX_RETRIES,
        ]);

        throw OperationVersionConflictException::create($operationId, $operation->lockVersion);
    }

    /**
     * Get the authenticated user's ID for access control.
     *
     * @return null|string User ID or null for system/anonymous access
     */
    private function getAuthenticatedUserId(): ?string
    {
        try {
            $user = $this->getCurrentUser();
            $id = $user->getAuthIdentifier();

            return $id !== null ? (string) $id : null;
        } catch (Throwable) {
            // No authenticated user - allow anonymous access for system operations
            return null;
        }
    }

    /**
     * Validate operation ID format.
     *
     * @param string $operationId Operation ID to validate
     *
     * @throws InvalidArgumentException If format is invalid
     */
    private function validateOperationId(string $operationId): void
    {
        // Validate format: UUID, ULID, or prefixed format (op_/op-)
        $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $operationId);
        $isUlid = (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $operationId);
        $isPrefixed = (bool) preg_match('/^op[_-][0-9a-z]+$/i', $operationId);

        if (!$isUuid && !$isUlid && !$isPrefixed) {
            throw InvalidFieldValueException::forField(
                'operation_id',
                'Operation ID must be a valid UUID, ULID, or prefixed format (op_/op- followed by alphanumeric)',
            );
        }
    }
}

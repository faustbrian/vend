<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Async\Functions;

use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Contracts\OperationRepositoryInterface;
use Cline\Forrst\Data\OperationData;
use Cline\Forrst\Exceptions\InvalidFieldTypeException;
use Cline\Forrst\Exceptions\InvalidFieldValueException;
use Cline\Forrst\Exceptions\OperationNotFoundException;
use Cline\Forrst\Extensions\Async\Descriptors\OperationStatusDescriptor;
use Cline\Forrst\Functions\AbstractFunction;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function is_string;
use function preg_match;

/**
 * Async operation status check function.
 *
 * Implements forrst.operation.status for retrieving operation status.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/async
 */
#[Descriptor(OperationStatusDescriptor::class)]
final class OperationStatusFunction extends AbstractFunction
{
    /**
     * Create a new operation status function instance.
     *
     * @param OperationRepositoryInterface $repository Operation repository
     * @param LoggerInterface              $logger     Logger for recording operations
     */
    public function __construct(
        private readonly OperationRepositoryInterface $repository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Execute the operation status function.
     *
     * Operations are scoped to the authenticated user. Users can only view
     * operations they own. Returns "not found" for both missing and unauthorized
     * operations to prevent enumeration attacks.
     *
     * @throws OperationNotFoundException If the operation does not exist or user is unauthorized
     *
     * @return array<string, mixed> Operation status details
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

        // Find operation with access control - returns null if not found OR unauthorized
        $operation = $this->repository->find($operationId, $userId);

        if (!$operation instanceof OperationData) {
            $this->logger->warning('Operation not found or unauthorized', [
                'operation_id' => $operationId,
                'user_id' => $userId,
            ]);

            // Generic error to prevent enumeration attacks
            throw OperationNotFoundException::create($operationId);
        }

        $this->logger->debug('Operation status retrieved', [
            'operation_id' => $operationId,
            'status' => $operation->status->value,
            'function' => $operation->function,
            'user_id' => $userId,
        ]);

        return $operation->toArray();
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

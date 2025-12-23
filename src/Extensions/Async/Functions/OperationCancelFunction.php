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
use Cline\Forrst\Exceptions\OperationCannotCancelException;
use Cline\Forrst\Exceptions\OperationNotFoundException;
use Cline\Forrst\Extensions\Async\Descriptors\OperationCancelDescriptor;
use Cline\Forrst\Functions\AbstractFunction;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function assert;
use function is_string;

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
     * @throws OperationCannotCancelException If the operation is in a terminal state
     * @throws OperationNotFoundException     If the operation ID does not exist
     *
     * @return array{operation_id: string, status: string, cancelled_at: string} Cancellation result
     */
    public function __invoke(): array
    {
        $operationId = $this->requestObject->getArgument('operation_id');

        if (!is_string($operationId)) {
            throw new \InvalidArgumentException('Operation ID must be a string');
        }

        $this->validateOperationId($operationId);

        $operation = $this->repository->find($operationId);

        if (!$operation instanceof OperationData) {
            $this->logger->warning('Operation not found for cancellation', [
                'operation_id' => $operationId,
            ]);

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
        );

        $this->repository->save($cancelledOperation);

        $this->logger->info('Operation cancelled successfully', [
            'operation_id' => $operationId,
            'function' => $operation->function,
        ]);

        return [
            'operation_id' => $operationId,
            'status' => 'cancelled',
            'cancelled_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Validate operation ID format.
     *
     * @param string $operationId Operation ID to validate
     *
     * @throws \InvalidArgumentException If format is invalid
     */
    private function validateOperationId(string $operationId): void
    {
        if (!preg_match('/^op_[0-9a-f]{24}$/', $operationId)) {
            throw new \InvalidArgumentException(
                "Invalid operation ID format: {$operationId}. Expected format: op_<24 hex characters>",
            );
        }
    }
}

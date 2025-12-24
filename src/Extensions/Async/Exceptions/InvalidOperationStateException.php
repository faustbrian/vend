<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Async\Exceptions;

use Cline\Forrst\Data\OperationStatus;
use RuntimeException;

use function sprintf;

/**
 * Exception thrown when an operation state transition is invalid.
 *
 * Indicates an attempt to perform an operation that is not valid for the
 * current state. Examples include:
 * - Completing an already completed operation
 * - Updating progress on a cancelled operation
 * - Cancelling a failed operation
 * - Transitioning from a terminal state
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidOperationStateException extends RuntimeException
{
    /**
     * Create exception for invalid state transition.
     *
     * @param string          $operationId     Operation ID
     * @param string          $attemptedAction What action was attempted
     * @param OperationStatus $currentStatus   Current operation status
     */
    public static function cannotTransition(string $operationId, string $attemptedAction, OperationStatus $currentStatus): self
    {
        return new self(sprintf(
            'Cannot %s operation %s: %s',
            $attemptedAction,
            $operationId,
            $currentStatus->value,
        ));
    }
}

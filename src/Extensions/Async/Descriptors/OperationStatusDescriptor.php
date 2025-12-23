<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Async\Descriptors;

use Cline\Forrst\Contracts\DescriptorInterface;
use Cline\Forrst\Discovery\FunctionDescriptor;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Functions\FunctionUrn;

/**
 * Descriptor for the operation status function.
 *
 * Defines discovery metadata for the forrst.operation.status system function.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class OperationStatusDescriptor implements DescriptorInterface
{
    public static function create(): FunctionDescriptor
    {
        return FunctionDescriptor::make()
            ->urn(FunctionUrn::OperationStatus)
            ->summary('Check status of an async operation')
            ->argument(
                name: 'operation_id',
                schema: [
                    'type' => 'string',
                    'pattern' => '^op_[a-f0-9]{24}$',
                    'minLength' => 27,
                    'maxLength' => 27,
                    'description' => 'Operation identifier (format: op_ followed by 24 hex characters)',
                ],
                required: true,
                description: 'Unique operation identifier',
            )
            ->result(
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'string',
                            'description' => 'Operation ID',
                        ],
                        'function' => [
                            'type' => 'string',
                            'description' => 'Function that was called',
                        ],
                        'version' => [
                            'type' => 'string',
                            'description' => 'Function version',
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['pending', 'processing', 'completed', 'failed', 'cancelled'],
                            'description' => 'Operation status',
                        ],
                        'progress' => [
                            'type' => 'number',
                            'minimum' => 0.0,
                            'maximum' => 1.0,
                            'description' => 'Progress percentage (0-1)',
                        ],
                        'result' => [
                            'description' => 'Operation result (when completed)',
                        ],
                        'errors' => [
                            'type' => 'array',
                            'description' => 'Errors (when failed)',
                        ],
                        'started_at' => [
                            'type' => 'string',
                            'format' => 'date-time',
                            'description' => 'When operation started',
                        ],
                        'completed_at' => [
                            'type' => 'string',
                            'format' => 'date-time',
                            'description' => 'When operation completed',
                        ],
                    ],
                    'required' => ['id', 'status'],
                ],
                description: 'Operation status response',
            )
            ->error(
                code: ErrorCode::AsyncOperationNotFound,
                message: 'Operation not found',
                description: 'The specified operation ID does not exist',
            );
    }
}

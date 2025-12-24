<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\AtomicLock\Descriptors;

use Cline\Forrst\Contracts\DescriptorInterface;
use Cline\Forrst\Discovery\FunctionDescriptor;
use Cline\Forrst\Enums\ErrorCode;
use Cline\Forrst\Functions\FunctionUrn;

/**
 * Descriptor for the lock status function.
 *
 * Defines discovery metadata for the forrst.locks.status system function.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class LockStatusDescriptor implements DescriptorInterface
{
    public static function create(): FunctionDescriptor
    {
        return FunctionDescriptor::make()
            ->urn(FunctionUrn::LocksStatus)
            ->summary('Check the status of a lock')
            ->argument(
                name: 'key',
                schema: [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 200,
                    'pattern' => '^[a-zA-Z0-9\-_:.]+$',
                ],
                required: true,
                description: 'Lock key (with scope prefix if applicable). Must contain only alphanumeric characters, dash, underscore, colon, and dot.',
            )
            ->result(
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'The lock key',
                        ],
                        'locked' => [
                            'type' => 'boolean',
                            'description' => 'Whether the lock is currently held',
                        ],
                        'owner' => [
                            'type' => 'string',
                            'description' => 'Owner token (only if locked)',
                        ],
                        'acquired_at' => [
                            'type' => 'string',
                            'format' => 'date-time',
                            'description' => 'When lock was acquired (only if locked)',
                        ],
                        'expires_at' => [
                            'type' => 'string',
                            'format' => 'date-time',
                            'description' => 'When lock expires (only if locked)',
                        ],
                        'ttl_remaining' => [
                            'type' => 'integer',
                            'description' => 'Seconds until lock expires (only if locked)',
                        ],
                    ],
                    'required' => ['key', 'locked'],
                ],
                description: 'Lock status information',
            )
            ->error(
                code: ErrorCode::InvalidArguments,
                message: 'Invalid or missing key',
                description: 'The lock key is required and must be a non-empty string',
            );
    }
}

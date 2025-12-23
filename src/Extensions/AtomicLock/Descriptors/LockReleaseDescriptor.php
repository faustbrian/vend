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
 * Descriptor for the lock release function.
 *
 * Defines discovery metadata for the forrst.locks.release system function.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class LockReleaseDescriptor implements DescriptorInterface
{
    public static function create(): FunctionDescriptor
    {
        return FunctionDescriptor::make()
            ->urn(FunctionUrn::LocksRelease)
            ->summary('Release a lock with ownership verification')
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
            ->argument(
                name: 'owner',
                schema: [
                    'type' => 'string',
                    'minLength' => 1,
                    'format' => 'uuid',
                ],
                required: true,
                description: 'Owner token from lock acquisition (UUID format)',
            )
            ->result(
                schema: [
                    'type' => 'object',
                    'properties' => [
                        'released' => [
                            'type' => 'boolean',
                            'description' => 'Whether release was successful',
                        ],
                        'key' => [
                            'type' => 'string',
                            'description' => 'The lock key',
                        ],
                    ],
                    'required' => ['released', 'key'],
                ],
                description: 'Lock release result',
            )
            ->error(
                code: ErrorCode::LockNotFound,
                message: 'Lock does not exist',
                description: 'The specified lock does not exist or has already expired',
            )
            ->error(
                code: ErrorCode::LockOwnershipMismatch,
                message: 'Lock is owned by a different process',
                description: 'The provided owner token does not match the lock owner',
            );
    }
}

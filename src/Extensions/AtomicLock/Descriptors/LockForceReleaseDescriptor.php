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
 * Descriptor for the lock force release function.
 *
 * Defines discovery metadata for the forrst.locks.forceRelease system function.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class LockForceReleaseDescriptor implements DescriptorInterface
{
    public static function create(): FunctionDescriptor
    {
        return FunctionDescriptor::make()
            ->urn(FunctionUrn::LocksForceRelease)
            ->summary('Force release a lock without ownership check (admin only)')
            ->description(
                'Administratively releases a lock without verifying ownership. ' .
                'This is a privileged operation that should be restricted to ' .
                'administrative users or automated cleanup processes. ' .
                'Regular applications should use forrst.locks.release instead. ' .
                'WARNING: Improper use can cause data corruption in critical sections.'
            )
            ->argument(
                name: 'key',
                schema: [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 200,
                    'pattern' => '^[a-zA-Z0-9\-_:.]+$',
                ],
                required: true,
                description: 'Full lock key including scope prefix (e.g., "forrst_lock:function_name:my_key"). Must contain only alphanumeric characters, dash, underscore, colon, and dot.',
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
                            'description' => 'The lock key that was released',
                        ],
                        'forced' => [
                            'type' => 'boolean',
                            'description' => 'Always true for force release operations',
                        ],
                    ],
                    'required' => ['released', 'key', 'forced'],
                ],
                description: 'Lock force release result',
            )
            ->error(
                code: ErrorCode::LockNotFound,
                message: 'Lock does not exist',
                description: 'The specified lock does not exist or has already been released',
            )
            ->error(
                code: ErrorCode::Unauthorized,
                message: 'Unauthorized',
                description: 'Force release requires administrative privileges',
            );
    }
}

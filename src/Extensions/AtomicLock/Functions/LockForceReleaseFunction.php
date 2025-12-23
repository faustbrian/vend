<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\AtomicLock\Functions;

use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Exceptions\LockKeyRequiredException;
use Cline\Forrst\Exceptions\LockNotFoundException;
use Cline\Forrst\Extensions\AtomicLock\AtomicLockExtension;
use Cline\Forrst\Extensions\AtomicLock\Descriptors\LockForceReleaseDescriptor;
use Cline\Forrst\Functions\AbstractFunction;

use function is_string;

/**
 * Lock force release function.
 *
 * Implements forrst.locks.forceRelease for administratively releasing locks
 * without ownership verification.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/atomic-lock
 */
#[Descriptor(LockForceReleaseDescriptor::class)]
final class LockForceReleaseFunction extends AbstractFunction
{
    /**
     * Create a new lock force release function instance.
     *
     * @param AtomicLockExtension $extension Atomic lock extension instance
     */
    public function __construct(
        private readonly AtomicLockExtension $extension,
    ) {}

    /**
     * Execute the lock force release function.
     *
     * @throws LockKeyRequiredException If key is missing
     * @throws LockNotFoundException    If lock does not exist
     *
     * @return array{released: bool, key: string, forced: bool} Release result
     */
    public function __invoke(): array
    {
        $key = $this->requestObject->getArgument('key');

        if (!is_string($key) || $key === '') {
            throw LockKeyRequiredException::create();
        }

        $released = $this->extension->forceReleaseLock($key);

        return [
            'released' => $released,
            'key' => $key,
            'forced' => true,
        ];
    }
}

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
use Cline\Forrst\Exceptions\LockOwnerRequiredException;
use Cline\Forrst\Exceptions\LockOwnershipMismatchException;
use Cline\Forrst\Extensions\AtomicLock\AtomicLockExtension;
use Cline\Forrst\Extensions\AtomicLock\Descriptors\LockReleaseDescriptor;
use Cline\Forrst\Functions\AbstractFunction;

use function is_string;

/**
 * Lock release function.
 *
 * Implements forrst.locks.release for releasing atomic locks with ownership
 * verification.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/atomic-lock
 */
#[Descriptor(LockReleaseDescriptor::class)]
final class LockReleaseFunction extends AbstractFunction
{
    /**
     * Create a new lock release function instance.
     *
     * @param AtomicLockExtension $extension Atomic lock extension instance
     */
    public function __construct(
        private readonly AtomicLockExtension $extension,
    ) {}

    /**
     * Execute the lock release function.
     *
     * @throws LockKeyRequiredException       If key is missing
     * @throws LockNotFoundException          If lock does not exist
     * @throws LockOwnerRequiredException     If owner is missing
     * @throws LockOwnershipMismatchException If owner does not match
     *
     * @return array{released: bool, key: string} Release result
     */
    public function __invoke(): array
    {
        $key = $this->requestObject->getArgument('key');
        $owner = $this->requestObject->getArgument('owner');

        if (!is_string($key) || $key === '') {
            throw LockKeyRequiredException::create();
        }

        if (!is_string($owner) || $owner === '') {
            throw LockOwnerRequiredException::create();
        }

        $released = $this->extension->releaseLock($key, $owner);

        return [
            'released' => $released,
            'key' => $key,
        ];
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Attributes;

use Attribute;
use Cline\Forrst\Contracts\DescriptorInterface;

/**
 * Links a function class to its descriptor class.
 *
 * Use this attribute on function classes to specify which descriptor class
 * contains the discovery metadata. This separates business logic from
 * schema definitions, keeping function classes focused and clean.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @example
 * ```php
 * #[Descriptor(UserListDescriptor::class)]
 * final class UserListFunction extends AbstractFunction
 * {
 *     public function __invoke(): array
 *     {
 *         // Pure business logic
 *     }
 * }
 * ```
 * @psalm-immutable
 *
 * @see DescriptorInterface
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Descriptor
{
    /**
     * Create a new descriptor attribute.
     *
     * @param class-string<DescriptorInterface> $class The descriptor class
     */
    public function __construct(
        public string $class,
    ) {}
}

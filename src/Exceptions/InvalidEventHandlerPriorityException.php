<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Exceptions;

use function get_debug_type;
use function sprintf;

/**
 * Exception thrown when an extension has an invalid priority value for an event handler.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidEventHandlerPriorityException extends InvalidExtensionConfigurationException
{
    /**
     * Create exception for an invalid priority value.
     *
     * @param string $extensionUrn Extension identifier
     * @param string $eventClass   Event class name
     * @param mixed  $priority     The invalid priority value
     */
    public static function forEvent(string $extensionUrn, string $eventClass, mixed $priority): self
    {
        return new self(sprintf(
            'Extension "%s" has invalid priority for event "%s": expected integer, got %s',
            $extensionUrn,
            $eventClass,
            get_debug_type($priority),
        ));
    }
}

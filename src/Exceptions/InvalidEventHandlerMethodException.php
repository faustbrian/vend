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
 * Exception thrown when an extension has an invalid method name for an event handler.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class InvalidEventHandlerMethodException extends InvalidExtensionConfigurationException
{
    /**
     * Create exception for an invalid method name.
     *
     * @param string $extensionUrn Extension identifier
     * @param string $eventClass   Event class name
     * @param mixed  $method       The invalid method value
     */
    public static function forEvent(string $extensionUrn, string $eventClass, mixed $method): self
    {
        return new self(sprintf(
            'Extension "%s" has invalid method for event "%s": expected string, got %s',
            $extensionUrn,
            $eventClass,
            get_debug_type($method),
        ));
    }
}

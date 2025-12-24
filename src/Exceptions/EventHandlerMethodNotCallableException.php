<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Exceptions;

use function sprintf;

/**
 * Exception thrown when an extension's event handler method is not callable (e.g., private).
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EventHandlerMethodNotCallableException extends InvalidExtensionConfigurationException
{
    /**
     * Create exception for a method that is not callable (e.g., private).
     *
     * @param string $extensionUrn   Extension identifier
     * @param string $eventClass     Event class name
     * @param string $method         Method name that is not callable
     * @param string $extensionClass Extension class name
     */
    public static function forEvent(
        string $extensionUrn,
        string $eventClass,
        string $method,
        string $extensionClass,
    ): self {
        return new self(sprintf(
            'Extension "%s" (%s) method "%s" for event "%s" is not callable (must be public)',
            $extensionUrn,
            $extensionClass,
            $method,
            $eventClass,
        ));
    }
}

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
 * Exception thrown when an extension references a non-existent event handler method.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EventHandlerMethodNotFoundException extends InvalidExtensionConfigurationException
{
    /**
     * Create exception for a method that doesn't exist on the extension.
     *
     * @param string $extensionUrn   Extension identifier
     * @param string $eventClass     Event class name
     * @param string $method         Method name that doesn't exist
     * @param string $extensionClass Extension class name
     */
    public static function forEvent(
        string $extensionUrn,
        string $eventClass,
        string $method,
        string $extensionClass,
    ): self {
        return new self(sprintf(
            'Extension "%s" (%s) references non-existent method "%s" for event "%s"',
            $extensionUrn,
            $extensionClass,
            $method,
            $eventClass,
        ));
    }
}

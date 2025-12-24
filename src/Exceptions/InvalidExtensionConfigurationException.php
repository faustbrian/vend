<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Exceptions;

use RuntimeException;

/**
 * Base exception for all extension configuration errors.
 *
 * Indicates the extension's getSubscribedEvents() returned an invalid
 * configuration that cannot be registered with the event subscriber.
 *
 * @author Brian Faust <brian@cline.sh>
 */
abstract class InvalidExtensionConfigurationException extends RuntimeException implements ForrstException
{
    // Abstract base - no factory methods
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Service metadata for discovery documents.
 *
 * Provides human-readable identification and descriptive information about
 * the Forrst service. Displayed in API explorers, documentation generators,
 * and client tooling to help developers identify and understand the service.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/specs/forrst/discovery#info-object
 */
final class InfoData extends Data
{
    /**
     * Create a new service information object.
     *
     * @param string           $title          Human-readable name of the service (e.g., "Payment Processing API",
     *                                         "User Management Service"). Displayed prominently in API explorers
     *                                         and documentation to identify the service to developers and users.
     * @param string           $version        Semantic version number of the service API (e.g., "2.1.0"). Indicates
     *                                         the overall API version and compatibility level, distinct from individual
     *                                         function versions. Helps clients track API evolution and breaking changes.
     * @param null|string      $description    Optional detailed explanation of the service's purpose, capabilities,
     *                                         and scope. Provides context about what the service does, what problems
     *                                         it solves, and how it fits into a larger system architecture.
     * @param null|string      $termsOfService Optional URL to the service's terms of service, acceptable use
     *                                         policy, or service level agreement. Specifies legal terms and
     *                                         usage constraints that clients must accept when using the service.
     * @param null|ContactData $contact        Optional contact information for the service maintainers or support
     *                                         team. Provides developers with a point of contact for questions,
     *                                         bug reports, or feature requests related to the service.
     * @param null|LicenseData $license        Optional licensing information for the service API. Specifies the
     *                                         legal terms under which the API can be used and integrated, including
     *                                         commercial use restrictions and attribution requirements.
     */
    public function __construct(
        string $title,
        string $version,
        public readonly ?string $description = null,
        public readonly ?string $termsOfService = null,
        public readonly ?ContactData $contact = null,
        public readonly ?LicenseData $license = null,
    ) {
        // Validate title
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new InvalidArgumentException('Service title cannot be empty');
        }

        if (mb_strlen($trimmedTitle) > 200) {
            throw new InvalidArgumentException(
                'Service title too long (max 200 characters, got ' . mb_strlen($trimmedTitle) . ')'
            );
        }

        $this->title = $trimmedTitle;

        // Validate semantic version
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)' .
            '(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)' .
            '(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?'  .
            '(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $version)) {
            throw new InvalidArgumentException(
                "Invalid semantic version: '{$version}'. Must follow semver format (e.g., '2.1.0')"
            );
        }

        $this->version = $version;
    }

    public readonly string $title;
    public readonly string $version;
}

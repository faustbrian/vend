<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst;

use Cline\Forrst\Exceptions\InvalidUrnFormatException;

use function implode;
use function preg_match;
use function str_contains;

/**
 * URN builder for Forrst identifiers.
 *
 * Provides static factory methods for constructing URNs that identify
 * extensions and functions in the Forrst protocol. All URNs follow the
 * format: urn:<vendor>:forrst:<type>:<name>
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/urn-naming
 */
final class Urn
{
    /**
     * The default vendor for core Forrst components.
     */
    public const string VENDOR = 'cline';

    /**
     * The protocol identifier.
     */
    public const string PROTOCOL = 'forrst';

    /**
     * Type identifier for extensions.
     */
    public const string TYPE_EXTENSION = 'ext';

    /**
     * Type identifier for functions.
     */
    public const string TYPE_FUNCTION = 'fn';

    /**
     * Build an extension URN.
     *
     * @param string      $name   Extension name in kebab-case (e.g., 'async', 'rate-limit')
     * @param null|string $vendor Vendor identifier (defaults to 'cline' for core extensions)
     *
     * @return string The full URN (e.g., 'urn:cline:forrst:ext:async')
     */
    public static function extension(string $name, ?string $vendor = null): string
    {
        return self::build(self::TYPE_EXTENSION, $name, $vendor);
    }

    /**
     * Build a function URN.
     *
     * @param string      $name   Function name in kebab-case with colons for hierarchy
     *                            (e.g., 'ping', 'locks:release', 'orders:create')
     * @param null|string $vendor Vendor identifier (defaults to 'cline' for core functions)
     *
     * @return string The full URN (e.g., 'urn:cline:forrst:fn:ping')
     */
    public static function function(string $name, ?string $vendor = null): string
    {
        return self::build(self::TYPE_FUNCTION, $name, $vendor);
    }

    /**
     * Build a function URN for an extension-provided function.
     *
     * @param string      $extension    Extension name in kebab-case (e.g., 'atomic-lock')
     * @param string      $functionName Function name in kebab-case (e.g., 'acquire', 'release')
     * @param null|string $vendor       Vendor identifier (defaults to 'cline')
     *
     * @return string The full URN (e.g., 'urn:cline:forrst:ext:atomic-lock:fn:acquire')
     */
    public static function extensionFunction(string $extension, string $functionName, ?string $vendor = null): string
    {
        $vendor ??= self::VENDOR;

        return implode(':', [
            'urn',
            $vendor,
            self::PROTOCOL,
            self::TYPE_EXTENSION,
            $extension,
            self::TYPE_FUNCTION,
            $functionName,
        ]);
    }

    /**
     * Parse a URN into its components.
     *
     * @param string $urn The URN to parse
     *
     * @throws InvalidUrnFormatException                                             If the URN is invalid
     * @return array{vendor: string, type: string, name: string, extension?: string}
     */
    public static function parse(string $urn): array
    {
        // Prevent ReDoS attacks
        if (strlen($urn) > 255) {
            throw InvalidUrnFormatException::create($urn);
        }

        // Extension-provided function: urn:vendor:forrst:ext:extension-name:fn:function-name
        if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:ext:([a-z][a-z0-9-]*):fn:(.+)$/', $urn, $matches)) {
            return [
                'vendor' => $matches[1],
                'type' => self::TYPE_FUNCTION,
                'extension' => $matches[2],
                'name' => $matches[3],
            ];
        }

        // Standard URN: urn:vendor:forrst:type:name
        if (preg_match('/^urn:([a-z][a-z0-9-]*):forrst:(ext|fn):(.+)$/', $urn, $matches)) {
            return [
                'vendor' => $matches[1],
                'type' => $matches[2],
                'name' => $matches[3],
            ];
        }

        throw InvalidUrnFormatException::create($urn);
    }

    /**
     * Check if a string is a valid Forrst URN.
     *
     * @param string $urn The string to validate
     *
     * @return bool True if valid URN format
     */
    public static function isValid(string $urn): bool
    {
        // Prevent ReDoS attacks
        if (strlen($urn) > 255) {
            return false;
        }

        return (bool) preg_match('/^urn:[a-z][a-z0-9-]*:forrst:(ext|fn)(:[a-z][a-z0-9-]*)+$/', $urn);
    }

    /**
     * Check if a URN is for a core Forrst component.
     *
     * @param string $urn The URN to check
     *
     * @return bool True if the URN uses the 'cline' vendor
     */
    public static function isCore(string $urn): bool
    {
        // Add length check for consistency
        if (strlen($urn) > 255) {
            return false;
        }

        return str_contains($urn, 'urn:'.self::VENDOR.':');
    }

    /**
     * Build a URN from components.
     *
     * @param string      $type   Resource type ('ext' or 'fn')
     * @param string      $name   Resource name
     * @param null|string $vendor Vendor identifier
     *
     * @return string The full URN
     */
    private static function build(string $type, string $name, ?string $vendor = null): string
    {
        $vendor ??= self::VENDOR;

        return implode(':', ['urn', $vendor, self::PROTOCOL, $type, $name]);
    }
}

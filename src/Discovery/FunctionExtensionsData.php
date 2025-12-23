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
 * Per-function extension support configuration.
 *
 * Allows individual functions to declare which protocol extensions they accept,
 * overriding server-wide extension defaults. Use either supported (allowlist)
 * or excluded (blocklist), but not both. Enables fine-grained control over
 * extension availability when different functions have varying capabilities.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 * @see https://docs.cline.sh/specs/forrst/system-functions#extension-support
 */
final class FunctionExtensionsData extends Data
{
    /**
     * Create a new function extension configuration.
     *
     * @param null|array<int, string> $supported Allowlist of extension names this function explicitly
     *                                           supports (e.g., ["query", "batch"]). When specified,
     *                                           only these extensions are available for this function
     *                                           regardless of server-wide settings. Use when a function
     *                                           has specific extension requirements or capabilities.
     *                                           Mutually exclusive with excluded field.
     * @param null|array<int, string> $excluded  Blocklist of extension names this function explicitly
     *                                           rejects (e.g., ["query", "cache"]). When specified,
     *                                           these extensions are unavailable for this function even
     *                                           if enabled server-wide. Use when a function cannot support
     *                                           certain extensions due to implementation constraints.
     *                                           Mutually exclusive with supported field.
     */
    public function __construct(
        public readonly ?array $supported = null,
        public readonly ?array $excluded = null,
    ) {
        $this->validateMutualExclusivity();
        $this->validateExtensionNames($supported ?? []);
        $this->validateExtensionNames($excluded ?? []);
        $this->validateNoDuplicates($supported ?? [], 'supported');
        $this->validateNoDuplicates($excluded ?? [], 'excluded');
    }

    /**
     * Ensure supported and excluded are mutually exclusive.
     *
     * @throws InvalidArgumentException
     */
    private function validateMutualExclusivity(): void
    {
        if ($this->supported !== null && $this->excluded !== null) {
            throw new InvalidArgumentException(
                'Cannot specify both "supported" and "excluded"—they are mutually exclusive. '
                .'Use "supported" for allowlist or "excluded" for blocklist, not both.'
            );
        }

        // Validate arrays are not empty when specified
        if ($this->supported !== null && empty($this->supported)) {
            throw new InvalidArgumentException(
                'Supported extensions array cannot be empty—use null to inherit server defaults'
            );
        }

        if ($this->excluded !== null && empty($this->excluded)) {
            throw new InvalidArgumentException(
                'Excluded extensions array cannot be empty—use null for no exclusions'
            );
        }
    }

    /**
     * Validate extension names follow expected format.
     *
     * @param array<int, string> $extensions
     *
     * @throws InvalidArgumentException
     */
    private function validateExtensionNames(array $extensions): void
    {
        foreach ($extensions as $index => $name) {
            if (!\is_string($name)) {
                throw new InvalidArgumentException(
                    "Extension name at index {$index} must be a string, got: ".\gettype($name)
                );
            }

            // Extension names should follow kebab-case or URN format
            if (!\preg_match('/^[a-z][a-z0-9-]*$/', $name) && !\str_starts_with($name, 'urn:')) {
                throw new InvalidArgumentException(
                    "Invalid extension name '{$name}' at index {$index}. "
                    ."Must be kebab-case (e.g., 'query', 'atomic-lock') or URN format "
                    ."(e.g., 'urn:forrst:ext:query')"
                );
            }

            // Warn about uppercase (common mistake)
            if ($name !== \strtolower($name) && !\str_starts_with($name, 'urn:')) {
                \trigger_error(
                    "Warning: Extension name '{$name}' contains uppercase characters. "
                    ."Extension names are case-sensitive and typically lowercase.",
                    \E_USER_WARNING
                );
            }
        }
    }

    /**
     * Validate no duplicate extension names exist.
     *
     * @param array<int, string> $extensions
     *
     * @throws InvalidArgumentException
     */
    private function validateNoDuplicates(array $extensions, string $fieldName): void
    {
        $unique = \array_unique($extensions);
        if (\count($unique) !== \count($extensions)) {
            $duplicates = \array_diff($extensions, $unique);
            throw new InvalidArgumentException(
                "Field '{$fieldName}' contains duplicate extension names: "
                .\json_encode(\array_values($duplicates))
            );
        }
    }
}

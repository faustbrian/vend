<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use Spatie\LaravelData\Data;

/**
 * Example definition for discovery documents.
 *
 * Supports two usage patterns:
 * 1. Value Examples (components.examples) - Simple value with metadata using the `value` field
 * 2. Function Examples - Request/response pairs using `arguments` and `result` fields
 *
 * For components.examples, use `value` or `externalValue` to provide the example data.
 * For function examples or example pairings, use `arguments` and `result` fields.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/discovery#componentsexamples
 */
final class ExampleData extends Data
{
    /**
     * Create a new example.
     *
     * @param string                    $name          Unique identifier for this example (e.g., "PublishedEvent",
     *                                                 "basic_usage"). Used to reference the example using $ref
     *                                                 notation in content descriptors or function definitions.
     * @param null|string               $summary       Brief one-line description of what this example shows
     *                                                 (e.g., "A typical published event"). Displayed as the
     *                                                 example title in documentation.
     * @param null|string               $description   Detailed explanation of the example including context,
     *                                                 preconditions, and usage notes. Supports Markdown.
     * @param mixed                     $value         Embedded literal example value for value examples.
     *                                                 Used in components.examples to provide standalone values.
     *                                                 Mutually exclusive with externalValue.
     * @param null|string               $externalValue URL to an external resource containing the example value.
     *                                                 Use when the example is large or managed separately.
     *                                                 Mutually exclusive with value field.
     * @param null|array<string, mixed> $arguments     Example parameter values for function examples. Keys are
     *                                                 parameter names with values showing valid example data.
     * @param mixed                     $result        Expected successful response for function examples.
     *                                                 Shows the structure and content of a typical result.
     * @param null|array<string, mixed> $error         Expected error response for error examples.
     *                                                 Contains error code, message, and details.
     *                                                 Mutually exclusive with result field.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly mixed $value = null,
        public readonly ?string $externalValue = null,
        public readonly ?array $arguments = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {
        $this->validateMutualExclusivity();
        $this->validateExternalValue();
    }

    /**
     * Validates mutual exclusivity constraints between fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validateMutualExclusivity(): void
    {
        // value and externalValue are mutually exclusive
        if ($this->value !== null && $this->externalValue !== null) {
            throw new \InvalidArgumentException(
                'Cannot specify both "value" and "externalValue"—they are mutually exclusive'
            );
        }

        // result and error are mutually exclusive
        if ($this->result !== null && $this->error !== null) {
            throw new \InvalidArgumentException(
                'Cannot specify both "result" and "error"—use separate examples for success/error cases'
            );
        }
    }

    /**
     * Validates that externalValue contains a valid URL.
     *
     * @throws \InvalidArgumentException
     */
    private function validateExternalValue(): void
    {
        if ($this->externalValue !== null) {
            if (filter_var($this->externalValue, \FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException(
                    "Invalid URL in externalValue: '{$this->externalValue}'"
                );
            }

            // Warn if not using HTTPS for security
            if (str_starts_with($this->externalValue, 'http://')) {
                trigger_error(
                    "Warning: externalValue should use HTTPS for security: '{$this->externalValue}'",
                    \E_USER_WARNING
                );
            }
        }
    }
}

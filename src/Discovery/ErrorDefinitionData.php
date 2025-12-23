<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Discovery;

use BackedEnum;
use Spatie\LaravelData\Data;

/**
 * Error definition for function error documentation.
 *
 * Describes a specific error condition that a function may return. Used in
 * discovery documents to document expected error responses, enabling clients
 * to implement proper error handling and display meaningful error messages.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/errors
 * @see https://docs.cline.sh/specs/forrst/discovery#error-definition-object
 */
final class ErrorDefinitionData extends Data
{
    /**
     * Machine-readable error code identifier.
     */
    public readonly string $code;

    /**
     * Create a new error definition.
     *
     * @param BackedEnum|string         $code        Machine-readable error code identifier following SCREAMING_SNAKE_CASE
     *                                               convention (e.g., ErrorCode::InvalidArgument, "RESOURCE_NOT_FOUND"). Used by
     *                                               clients to programmatically identify and handle specific error conditions
     *                                               without parsing human-readable messages.
     * @param string                    $message     Human-readable error message template describing the error condition.
     *                                               May include variable placeholders that are populated with context-specific
     *                                               values when the error occurs. Displayed to end users in error dialogs
     *                                               and logging output.
     * @param null|string               $description Optional detailed explanation of when this error occurs, what
     *                                               causes it, and how to resolve it. Provides additional context
     *                                               beyond the brief message for documentation and troubleshooting.
     * @param null|array<string, mixed> $details     JSON Schema definition for the error's details field.
     *                                               Specifies the structure and validation rules for
     *                                               additional error metadata, enabling type-safe error
     *                                               handling and validation in client implementations.
     */
    public function __construct(
        BackedEnum|string $code,
        public readonly string $message,
        public readonly ?string $description = null,
        public readonly ?array $details = null,
    ) {
        $this->code = match (true) {
            $code instanceof BackedEnum => (string) $code->value,
            default => $this->validateCode($code),
        };

        $this->validateMessagePlaceholders($message);

        if ($details !== null) {
            $this->validateJsonSchema($details);
        }
    }

    /**
     * Validate error code follows SCREAMING_SNAKE_CASE convention.
     *
     * @throws \InvalidArgumentException
     */
    private function validateCode(string $code): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $code)) {
            throw new \InvalidArgumentException(
                "Error code must follow SCREAMING_SNAKE_CASE convention. Got: '{$code}'"
            );
        }

        return $code;
    }

    /**
     * Validate message uses safe numbered placeholders.
     *
     * @throws \InvalidArgumentException
     */
    private function validateMessagePlaceholders(string $message): void
    {
        // Check for potentially unsafe named placeholders
        if (preg_match('/\{[A-Za-z_][A-Za-z0-9_]*\}/', $message)) {
            trigger_error(
                'Warning: Error message uses named placeholders like {fieldName}. '
                .'Consider using numbered placeholders {0}, {1} to prevent injection.',
                E_USER_WARNING
            );
        }

        // Validate numbered placeholders are sequential
        preg_match_all('/\{(\d+)\}/', $message, $matches);
        if (!empty($matches[1])) {
            $indices = array_map('intval', $matches[1]);
            sort($indices);
            $expected = range(0, \count($indices) - 1);

            if ($indices !== $expected) {
                throw new \InvalidArgumentException(
                    'Message placeholders must be sequential starting from {0}. '
                    .'Found: '.implode(', ', array_map(fn ($i) => "{{$i}}", $indices))
                );
            }
        }
    }

    /**
     * Validate details field contains valid JSON Schema.
     *
     * @param array<string, mixed> $details
     * @param int                  $depth  Current nesting depth (for DoS prevention)
     *
     * @throws \InvalidArgumentException
     */
    private function validateJsonSchema(array $details, int $depth = 0): void
    {
        if ($depth > 10) {
            throw new \InvalidArgumentException(
                'JSON Schema nesting too deep (max 10 levels)'
            );
        }

        if (!isset($details['type'])) {
            throw new \InvalidArgumentException(
                'JSON Schema in details must specify a "type" property'
            );
        }

        $validTypes = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];
        if (!\in_array($details['type'], $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid JSON Schema type '{$details['type']}'. "
                .'Must be one of: '.implode(', ', $validTypes)
            );
        }

        // If type is object, validate properties exist
        if ($details['type'] === 'object' && isset($details['properties'])) {
            if (!\is_array($details['properties'])) {
                throw new \InvalidArgumentException(
                    'JSON Schema "properties" must be an object/array'
                );
            }

            // Recursively validate nested schemas
            foreach ($details['properties'] as $propName => $propSchema) {
                if (!\is_array($propSchema) || !isset($propSchema['type'])) {
                    throw new \InvalidArgumentException(
                        "Property '{$propName}' must have a valid JSON Schema with 'type'"
                    );
                }
                $this->validateJsonSchema($propSchema, $depth + 1);
            }
        }

        // If type is array, validate items exist
        if ($details['type'] === 'array' && isset($details['items'])) {
            if (!\is_array($details['items']) || !isset($details['items']['type'])) {
                throw new \InvalidArgumentException(
                    'JSON Schema "items" must be a valid schema with "type"'
                );
            }
            $this->validateJsonSchema($details['items'], $depth + 1);
        }
    }
}

<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\JsonSchema;

use Cline\Forrst\Exceptions\JsonSchemaException;
use Symfony\Component\Intl\Timezones;

use function array_map;
use function explode;
use function implode;
use function is_string;
use function mb_substr;
use function method_exists;
use function str_starts_with;
use function throw_if;

/**
 * Transforms individual Laravel validation rules into JSON Schema constraints.
 *
 * Converts Laravel validation rule syntax into equivalent JSON Schema constraints,
 * enabling automatic API documentation generation and client-side validation.
 * Supports most Laravel validation rules with equivalent JSON Schema mappings.
 * Rules that cannot be represented in JSON Schema (like database queries) throw
 * exceptions.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @todo Refactor this class into per-rule classes
 * @see https://docs.cline.sh/forrst/
 */
final class RuleTransformer
{
    /**
     * Transform Laravel validation rules into JSON Schema properties.
     *
     * Processes each validation rule for a field and generates the corresponding
     * JSON Schema constraints. Handles rule parameters, conditional rules, and
     * complex validation scenarios by building appropriate schema structures.
     * Rules that cannot be represented in JSON Schema (bail, exists, unique, etc.)
     * throw JsonSchemaException.
     *
     * ```php
     * $schema = RuleTransformer::transform('email', ['required', 'email', 'max:255']);
     * // Returns: ['type' => 'string', 'format' => 'email', 'maxLength' => 255, 'required' => ['email']]
     * ```
     *
     * @param string                    $field The field name being validated, used for self-referential
     *                                         schema constructs like 'required' arrays
     * @param array<int, object|string> $rules Array of Laravel validation rules to transform,
     *                                         supporting both string and object rule instances
     *
     * @throws JsonSchemaException When encountering rules that cannot be converted to JSON Schema
     *
     * @return array<string, mixed> JSON Schema properties for the field, including type, format, constraints, etc.
     */
    public static function transform(string $field, array $rules): array
    {
        if (trim($field) === '') {
            throw new \InvalidArgumentException('Field name cannot be empty');
        }

        foreach ($rules as $rule) {
            if (!is_string($rule) && !is_object($rule)) {
                throw new \InvalidArgumentException(
                    sprintf('Rule must be string or object, %s given', get_debug_type($rule)),
                );
            }
        }

        $fieldSchema = [];

        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                if (!method_exists($rule, '__toString')) {
                    continue;
                }

                $rule = (string) $rule;
            }

            // Accepted
            if ($rule === 'accepted') {
                $fieldSchema['enum'] = [true, 'true', 1, '1', 'yes', 'on'];
            }

            // Accepted If
            if (str_starts_with($rule, 'accepted_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 12));

                $fieldSchema['properties'][$field] = [
                    'if' => [
                        'properties' => [
                            $otherField => ['const' => $value],
                        ],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Active URL
            if ($rule === 'active_url') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['format'] = 'uri';
            }

            // After (Date)
            if (str_starts_with($rule, 'after:')) {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'date-time';
                $fieldSchema['properties'][$field]['exclusiveMinimum'] = mb_substr($rule, 6);
            }

            // After Or Equal (Date)
            if (str_starts_with($rule, 'after_or_equal:')) {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'date-time';
                $fieldSchema['properties'][$field]['minimum'] = mb_substr($rule, 15);
            }

            // Alpha
            if ($rule === 'alpha') {
                $fieldSchema['pattern'] = '^[a-zA-Z]+$';
            }

            // Alpha Dash
            if ($rule === 'alpha_dash') {
                $fieldSchema['pattern'] = '^[a-zA-Z0-9-_]+$';
            }

            // Alpha Numeric
            if ($rule === 'alpha_num') {
                $fieldSchema['pattern'] = '^[a-zA-Z0-9]+$';
            }

            // Array
            if ($rule === 'array') {
                $fieldSchema['type'] = 'array';
            }

            // Ascii
            if ($rule === 'ascii') {
                $fieldSchema['pattern'] = '^[\x00-\x7F]+$';
            }

            // Bail
            throw_if($rule === 'bail', JsonSchemaException::invalidRule('bail'));

            // Before (Date)
            if (str_starts_with($rule, 'before:')) {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'date-time';
                $fieldSchema['properties'][$field]['exclusiveMaximum'] = mb_substr($rule, 7);
            }

            // Before Or Equal (Date)
            if (str_starts_with($rule, 'before_or_equal:')) {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'date-time';
                $fieldSchema['properties'][$field]['maximum'] = mb_substr($rule, 16);
            }

            // Between
            if (str_starts_with($rule, 'between:')) {
                [$min, $max] = explode(',', mb_substr($rule, 8));

                $fieldSchema['minimum'] = (int) $min;
                $fieldSchema['maximum'] = (int) $max;
            }

            // Boolean
            if ($rule === 'boolean') {
                $fieldSchema['type'] = 'boolean';
            }

            // Confirmed
            if ($rule === 'confirmed') {
                $fieldSchema['properties'][$field]['const'] = ['$data', $field];
            }

            // Current Password
            throw_if($rule === 'current_password', JsonSchemaException::invalidRule('current_password'));

            // Date
            if ($rule === 'date') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['format'] = 'date';
            }

            // Date Equals
            if (str_starts_with($rule, 'date_equals:')) {
                $fieldSchema['type'] = 'string';
                $fieldSchema['format'] = 'date';
                $fieldSchema['enum'] = [mb_substr($rule, 12)];
            }

            // Date Format
            if (str_starts_with($rule, 'date_format:')) {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = mb_substr($rule, 12);
            }

            // Decimal
            if ($rule === 'decimal') {
                $fieldSchema['properties'][$field]['type'] = 'number';
                $fieldSchema['properties'][$field]['not'] = ['multipleOf' => 1];
            }

            // Declined
            if ($rule === 'declined') {
                $fieldSchema['enum'] = [false, 'false', 0, '0', 'no', 'off'];
            }

            // Declined If
            if (str_starts_with($rule, 'declined_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 12));

                $fieldSchema['properties'][$field] = [
                    'not' => [
                        'if' => [
                            'properties' => [
                                $otherField => ['const' => $value],
                            ],
                        ],
                    ],
                ];
            }

            // Different
            if (str_starts_with($rule, 'different:')) {
                $fieldSchema['properties'][$field] = [
                    'properties' => [
                        $field => [
                            'not' => [
                                '$ref' => '#/properties/'.mb_substr($rule, 10),
                            ],
                        ],
                    ],
                ];
            }

            // Digits
            if (str_starts_with($rule, 'digits:')) {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[0-9]{'.mb_substr($rule, 7).'}$';
            }

            // Digits Between
            if (str_starts_with($rule, 'digits_between:')) {
                [$minDigits, $maxDigits] = explode(',', mb_substr($rule, 15));

                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[0-9]{'.$minDigits.','.$maxDigits.'}$';
            }

            // Dimensions (Image Files)
            throw_if($rule === 'dimensions', JsonSchemaException::invalidRule('dimensions'));

            // Distinct
            throw_if($rule === 'distinct', JsonSchemaException::invalidRule('distinct'));

            // Doesn't Start With
            if (str_starts_with($rule, 'doesnt_start_with:')) {
                $fieldSchema['pattern'] = '^(?!'.implode('|', array_map(preg_quote(...), explode(',', mb_substr($rule, 18)))).')';
            }

            // Doesn't End With
            if (str_starts_with($rule, 'doesnt_end_with:')) {
                $fieldSchema['pattern'] = '(?!'.implode('|', array_map(preg_quote(...), explode(',', mb_substr($rule, 16)))).')$';
            }

            // Email
            if ($rule === 'email') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['format'] = 'email';
            }

            // Ends With
            if (str_starts_with($rule, 'ends_with:')) {
                $fieldSchema['pattern'] = '('.implode('|', array_map(preg_quote(...), explode(',', mb_substr($rule, 10)))).')$';
            }

            // Enum
            if (str_starts_with($rule, 'enum:')) {
                $fieldSchema['enum'] = explode(',', mb_substr($rule, 5));
            }

            // Exclude
            if ($rule === 'exclude') {
                $fieldSchema['properties'][$field] = ['not' => ['type' => 'object']];
            }

            // Exclude If
            if (str_starts_with($rule, 'exclude_if:')) {
                [$otherField, $otherValue] = explode(',', mb_substr($rule, 11));

                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['const' => $otherValue]],
                    ],
                    'then' => [
                        'not' => ['required' => [$field]],
                    ],
                ];
            }

            // Exclude Unless
            if (str_starts_with($rule, 'exclude_unless:')) {
                [$otherField, $otherValue] = explode(',', mb_substr($rule, 15));

                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['not' => ['enum' => [$otherValue]]]],
                    ],
                    'then' => [
                        'not' => ['required' => [$field]],
                    ],
                ];
            }

            // Exclude With
            if (str_starts_with($rule, 'exclude_with:')) {
                $fieldSchema['allOf'][] = [
                    'if' => [
                        'anyOf' => array_map(
                            fn (string $f): array => ['required' => [$f]],
                            explode(',', mb_substr($rule, 13)),
                        ),
                    ],
                    'then' => [
                        'not' => ['required' => [$field]],
                    ],
                ];
            }

            // Exclude Without
            if (str_starts_with($rule, 'exclude_without:')) {
                $fieldSchema['allOf'][] = [
                    'if' => [
                        'allOf' => array_map(
                            fn (string $f): array => ['not' => ['required' => [$f]]],
                            explode(',', mb_substr($rule, 16)),
                        ),
                    ],
                    'then' => [
                        'not' => ['required' => [$field]],
                    ],
                ];
            }

            // Exists (Database)
            throw_if(str_starts_with($rule, 'exists:'), JsonSchemaException::invalidRule('exists'));

            // Extensions
            throw_if(str_starts_with($rule, 'extensions:'), JsonSchemaException::invalidRule('extensions'));

            // File
            throw_if($rule === 'file', JsonSchemaException::invalidRule('file'));

            // Filled
            if ($rule === 'filled') {
                $fieldSchema['properties'][$field]['required'] = true;
            }

            // Greater Than
            if (str_starts_with($rule, 'gt:')) {
                $fieldSchema['properties'][$field]['exclusiveMinimum'] = (float) mb_substr($rule, 3);
            }

            // Greater Than Or Equal
            if (str_starts_with($rule, 'gte:')) {
                $fieldSchema['properties'][$field]['minimum'] = (float) mb_substr($rule, 4);
            }

            // Hex Color
            if ($rule === 'hex_color') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^#(?:[0-9a-fA-F]{3}){1,2}$';
            }

            // Image (File)
            throw_if($rule === 'image', JsonSchemaException::invalidRule('image'));

            // In
            if (str_starts_with($rule, 'in:')) {
                $fieldSchema['type'] = 'string';
                $fieldSchema['enum'] = explode(',', mb_substr($rule, 3));
            }

            // In Array
            throw_if($rule === 'in_array', JsonSchemaException::invalidRule('in_array'));

            // Integer
            if ($rule === 'integer') {
                $fieldSchema['type'] = 'integer';
            }

            // IP Address
            if ($rule === 'ip') {
                $fieldSchema['anyOf'] = [
                    ['type' => 'string', 'format' => 'ipv4'],
                    ['type' => 'string', 'format' => 'ipv6'],
                ];
            }

            // IPv4 Address
            if ($rule === 'ipv4') {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'ipv4';
            }

            // IPv6 Address
            if ($rule === 'ipv6') {
                $fieldSchema['properties'][$field]['type'] = 'string';
                $fieldSchema['properties'][$field]['format'] = 'ipv6';
            }

            // JSON
            throw_if($rule === 'json', JsonSchemaException::invalidRule('json'));

            // Less Than
            if (str_starts_with($rule, 'lt:')) {
                $fieldSchema['properties'][$field]['exclusiveMaximum'] = (float) mb_substr($rule, 3);
            }

            // Less Than Or Equal
            if (str_starts_with($rule, 'lte:')) {
                $fieldSchema['properties'][$field]['maximum'] = (float) mb_substr($rule, 4);
            }

            // Lowercase
            if ($rule === 'lowercase') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[a-z]*$';
            }

            // MAC Address
            if ($rule === 'mac_address') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$';
            }

            // Max
            if (str_starts_with($rule, 'max:')) {
                $fieldSchema['maxLength'] = (int) mb_substr($rule, 4);
            }

            // Max Digits
            if (str_starts_with($rule, 'digits_max:')) {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[0-9]{1,'.mb_substr($rule, 11).'}$';
            }

            // MIME Types
            if (str_starts_with($rule, 'mimetypes:')) {
                $fieldSchema['properties'][$field]['contentMediaType'] = implode('|', explode(',', mb_substr($rule, 10)));
            }

            // MIME Type By File Extension
            throw_if(str_starts_with($rule, 'mimes:'), JsonSchemaException::invalidRule('mimes'));

            // Min
            if (str_starts_with($rule, 'min:')) {
                $fieldSchema['minLength'] = (int) mb_substr($rule, 4);
            }

            // Min Digits
            if (str_starts_with($rule, 'digits_min:')) {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[0-9]{'.mb_substr($rule, 11).',}$';
            }

            // Missing
            if ($rule === 'missing') {
                $fieldSchema['properties'][$field] = [
                    'not' => ['type' => 'null'],
                ];
            }

            // Missing If
            if (str_starts_with($rule, 'missing_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 11));

                $fieldSchema['properties'][$field] = [
                    'not' => [
                        'if' => [
                            'properties' => [
                                $otherField => ['const' => $value],
                            ],
                        ],
                    ],
                ];
            }

            // Missing Unless
            if (str_starts_with($rule, 'missing_unless:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 15));

                $fieldSchema['properties'][$field] = [
                    'not' => [
                        'if' => [
                            'properties' => [
                                $otherField => ['not' => ['const' => $value]],
                            ],
                        ],
                    ],
                ];
            }

            // Missing With
            if (str_starts_with($rule, 'missing_with:')) {
                $otherFields = explode(',', mb_substr($rule, 13));

                $ifCondition = ['properties' => []];

                foreach ($otherFields as $otherField) {
                    $ifCondition['properties'][$otherField] = ['type' => 'null'];
                }

                $fieldSchema['properties'][$field] = [
                    'not' => [
                        'if' => $ifCondition,
                    ],
                ];
            }

            // Missing With All
            if (str_starts_with($rule, 'missing_with_all:')) {
                $otherFields = explode(',', mb_substr($rule, 17));

                $ifCondition = ['properties' => []];

                foreach ($otherFields as $otherField) {
                    $ifCondition['properties'][$otherField] = ['type' => 'null'];
                }

                $fieldSchema['properties'][$field] = [
                    'not' => [
                        'if' => $ifCondition,
                    ],
                ];
            }

            // Multiple Of
            if (str_starts_with($rule, 'multiple_of:')) {
                $fieldSchema['properties'][$field]['type'] = 'number';
                $fieldSchema['properties'][$field]['multipleOf'] = (float) mb_substr($rule, 12);
            }

            // Not In
            if (str_starts_with($rule, 'not_in:')) {
                $fieldSchema['not'] = ['enum' => explode(',', mb_substr($rule, 7))];
            }

            // Not Regex
            if (str_starts_with($rule, 'not_regex:')) {
                $fieldSchema['not'] = ['pattern' => mb_substr($rule, 10)];
            }

            // Nullable
            if ($rule === 'nullable') {
                $fieldSchema['type'] = ['string', 'null'];
            }

            // Numeric
            if ($rule === 'numeric') {
                $fieldSchema['type'] = 'number';
            }

            // Password
            throw_if($rule === 'password', JsonSchemaException::invalidRule('password'));

            // Present
            if ($rule === 'present') {
                $fieldSchema['required'][] = $field;
            }

            // Present If
            if (str_starts_with($rule, 'present_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 11));

                $fieldSchema['properties'][$field] = [
                    'type' => 'string',
                ];

                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['const' => $value]],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Present Unless
            if (str_starts_with($rule, 'present_unless:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 15));

                $fieldSchema['properties'][$field] = [
                    'type' => 'string',
                ];

                $fieldSchema['allOf'][] = [
                    'if' => [
                        'not' => [
                            'properties' => [$otherField => ['const' => $value]],
                        ],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Present With
            if (str_starts_with($rule, 'present_with:')) {
                $otherFields = explode(',', mb_substr($rule, 13));

                $fieldSchema['type'] = 'string';

                $ifCondition = ['properties' => []];

                foreach ($otherFields as $otherField) {
                    $ifCondition['properties'][$otherField] = ['not' => ['type' => 'null']];
                }

                $fieldSchema['allOf'][] = [
                    'if' => $ifCondition,
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Present With All
            if (str_starts_with($rule, 'present_with_all:')) {
                $requiredFields = explode(',', mb_substr($rule, 17));

                $fieldSchema['type'] = 'string';

                $ifCondition = ['properties' => []];

                foreach ($requiredFields as $requiredField) {
                    $ifCondition['properties'][$requiredField] = ['not' => ['type' => 'null']];
                }

                $fieldSchema['allOf'][] = [
                    'if' => $ifCondition,
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Prohibited
            if ($rule === 'prohibited') {
                $fieldSchema['not']['required'][] = $field;
            }

            // Prohibited If
            if (str_starts_with($rule, 'prohibited_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 14));

                $fieldSchema['properties'][$field] = [
                    'type' => 'string',
                ];

                $ifCondition = [
                    'properties' => [
                        $otherField => ['const' => $value],
                    ],
                ];

                $fieldSchema['not']['if'] = [
                    'properties' => [
                        $otherField => ['const' => $value],
                    ],
                ];

                $fieldSchema['not']['then']['required'][] = $field;
            }

            // Prohibited Unless
            if (str_starts_with($rule, 'prohibited_unless:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 18));

                $fieldSchema['properties'][$field] = [
                    'type' => 'string',
                ];

                $fieldSchema['not']['if'] = [
                    'properties' => [
                        $otherField => ['not' => ['const' => $value]],
                    ],
                ];

                $fieldSchema['not']['then']['required'][] = $field;
            }

            // Prohibits

            if (str_starts_with($rule, 'prohibits:')) {
                $prohibitedFields = explode(',', mb_substr($rule, 10));

                $fieldSchema['properties'][$field] = [
                    'type' => 'string',
                ];

                $thenCondition = ['properties' => []];

                foreach ($prohibitedFields as $prohibitedField) {
                    $thenCondition['properties'][$prohibitedField] = [
                        'anyOf' => [
                            ['type' => 'null'],
                            ['type' => 'string', 'maxLength' => 0],
                            ['type' => 'array', 'items' => ['type' => 'null'], 'minItems' => 1],
                            ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                        ],
                    ];
                }

                $fieldSchema['allOf'][] = [
                    'if' => [
                        'not' => [
                            'anyOf' => [
                                ['type' => 'null'],
                                ['type' => 'string', 'maxLength' => 0],
                                ['type' => 'array', 'items' => ['type' => 'null'], 'minItems' => 1],
                                ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                            ],
                        ],
                    ],
                    'then' => [
                        'properties' => $thenCondition['properties'],
                    ],
                ];
            }

            // Regular Expression
            if (str_starts_with($rule, 'regex:')) {
                $fieldSchema['pattern'] = mb_substr($rule, 6);
            }

            // Required
            if ($rule === 'required') {
                $fieldSchema['required'][] = $field;
            }

            // Required If
            if (str_starts_with($rule, 'required_if:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 12));
                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['const' => $value]],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Required If Accepted
            if (str_starts_with($rule, 'required_if_accepted:')) {
                $otherField = mb_substr($rule, 21);
                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['enum' => ['yes', 'on', 1, '1', true, 'true']]],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Required Unless
            if (str_starts_with($rule, 'required_unless:')) {
                [$otherField, $value] = explode(',', mb_substr($rule, 16));
                $fieldSchema['allOf'][] = [
                    'if' => [
                        'properties' => [$otherField => ['not' => ['const' => $value]]],
                    ],
                    'then' => [
                        'required' => [$field],
                    ],
                ];
            }

            // Required With
            if (str_starts_with($rule, 'required_with:')) {
                $otherFields = explode(',', mb_substr($rule, 14));
                $fieldSchema['dependencies'][$field] = $otherFields;
            }

            // Required With All
            if (str_starts_with($rule, 'required_with_all:')) {
                $fieldSchema['dependencies'][$field] = [
                    'allOf' => array_map(
                        fn (string $f): array => ['required' => [$f]],
                        explode(',', mb_substr($rule, 18)),
                    ),
                ];
            }

            // Required Without
            if (str_starts_with($rule, 'required_without:')) {
                $fieldSchema['dependencies'][$field] = [
                    'not' => array_map(
                        fn (string $f): array => ['required' => [$f]],
                        explode(',', mb_substr($rule, 17)),
                    ),
                ];
            }

            // Required Without All
            if (str_starts_with($rule, 'required_without_all:')) {
                $fieldSchema['dependencies'][$field] = [
                    'not' => [
                        'allOf' => array_map(
                            fn (string $f): array => ['required' => [$f]],
                            explode(',', mb_substr($rule, 21)),
                        ),
                    ],
                ];
            }

            // Required Array Keys
            if (str_starts_with($rule, 'required_array_keys:')) {
                //
            }

            // Same
            throw_if(str_starts_with($rule, 'same:'), JsonSchemaException::invalidRule('same'));

            // Size
            if (str_starts_with($rule, 'size:')) {
                $fieldSchema['const'] = mb_substr($rule, 5);
            }

            // Sometimes
            throw_if($rule === 'sometimes', JsonSchemaException::invalidRule('sometimes'));

            // Starts With
            if (str_starts_with($rule, 'starts_with:')) {
                $fieldSchema['pattern'] = '^('.implode('|', array_map(preg_quote(...), explode(',', mb_substr($rule, 12)))).')';
            }

            // String
            if ($rule === 'string') {
                $fieldSchema['type'] = 'string';
            }

            // Timezone
            if ($rule === 'timezone') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['enum'] = Timezones::getIds();
            }

            // Unique (Database)
            throw_if(str_starts_with($rule, 'unique:'), JsonSchemaException::invalidRule('unique'));

            // Uppercase
            if ($rule === 'uppercase') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[A-Z]*$';
            }

            // URL
            if ($rule === 'url') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['format'] = 'uri';
            }

            // ULID
            if ($rule === 'ulid') {
                $fieldSchema['type'] = 'string';
                $fieldSchema['pattern'] = '^[0123456789ABCDEFGHJKMNPQRSTVWXYZ]{26}$';
            }

            // UUID
            if ($rule !== 'uuid') {
                continue;
            }

            $fieldSchema['type'] = 'string';
            $fieldSchema['format'] = 'uuid';
        }

        return $fieldSchema;
    }
}

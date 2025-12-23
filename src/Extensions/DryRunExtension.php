<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions;

use Cline\Forrst\Data\ExtensionData;
use Cline\Forrst\Data\RequestObjectData;
use Cline\Forrst\Data\ResponseData;
use Override;

/**
 * Dry-run extension handler.
 *
 * Validates mutations without executing them. Useful for previewing
 * changes, validating complex inputs, and implementing confirmation flows.
 *
 * Request options:
 * - enabled: boolean to enable dry-run mode
 * - include_diff: boolean to include before/after comparison
 * - include_side_effects: boolean to list operations that would occur
 *
 * Response data:
 * - valid: boolean indicating if operation would succeed
 * - would_affect: array of resources that would be modified
 * - diff: object with before/after state comparison
 * - side_effects: array of operations that would be triggered
 * - validation_errors: array of issues preventing execution
 * - estimated_duration: estimated execution time
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/specs/forrst/extensions/dry-run
 */
final class DryRunExtension extends AbstractExtension
{
    #[Override()]
    public function getUrn(): string
    {
        return ExtensionUrn::DryRun->value;
    }

    #[Override()]
    public function isErrorFatal(): bool
    {
        return true;
    }

    /**
     * Check if dry-run mode is enabled.
     *
     * Function handlers should check this before executing mutations. When true,
     * perform validation and build response metadata without persisting changes.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return bool True if dry-run mode is enabled
     */
    public function isEnabled(?array $options): bool
    {
        return ($options['enabled'] ?? false) === true;
    }

    /**
     * Check if diff should be included.
     *
     * When true, function should include before/after comparison showing what
     * would change. Useful for complex mutations affecting multiple fields.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return bool True if diff should be included in response
     */
    public function shouldIncludeDiff(?array $options): bool
    {
        return ($options['include_diff'] ?? false) === true;
    }

    /**
     * Check if side effects should be included.
     *
     * When true, function should list triggered operations like webhooks, emails,
     * or cascading updates. Helps clients understand full impact of mutation.
     *
     * @param null|array<string, mixed> $options Extension options from request
     *
     * @return bool True if side effects should be included in response
     */
    public function shouldIncludeSideEffects(?array $options): bool
    {
        return ($options['include_side_effects'] ?? false) === true;
    }

    /**
     * Build a successful dry-run response.
     *
     * Creates response indicating validation passed and mutation would succeed.
     * Includes metadata about affected resources, state changes, side effects,
     * and estimated execution time. Validates input structures.
     *
     * @param RequestObjectData                     $request           Original request
     * @param array<int, array<string, mixed>>      $wouldAffect       Resources that would be modified
     * @param null|array<string, mixed>             $diff              Before/after state comparison
     * @param null|array<int, array<string, mixed>> $sideEffects       Operations that would be triggered
     * @param null|array{value: int, unit: string}  $estimatedDuration Estimated execution time
     *
     * @return ResponseData Dry-run response with valid=true
     *
     * @throws \InvalidArgumentException If wouldAffect entries are missing required fields
     */
    public function buildValidResponse(
        RequestObjectData $request,
        array $wouldAffect = [],
        ?array $diff = null,
        ?array $sideEffects = null,
        ?array $estimatedDuration = null,
    ): ResponseData {
        // Validate wouldAffect entries
        foreach ($wouldAffect as $entry) {
            if (!isset($entry['type']) || !isset($entry['action'])) {
                throw new \InvalidArgumentException(
                    'would_affect entries must have type and action fields',
                );
            }
        }
        $data = [
            'valid' => true,
            'would_affect' => $wouldAffect,
        ];

        if ($diff !== null) {
            $data['diff'] = $diff;
        }

        if ($sideEffects !== null) {
            $data['side_effects'] = $sideEffects;
        }

        if ($estimatedDuration !== null) {
            $data['estimated_duration'] = $estimatedDuration;
        }

        return ResponseData::success(
            result: null,
            id: $request->id,
            extensions: [
                ExtensionData::response(ExtensionUrn::DryRun->value, $data),
            ],
        );
    }

    /**
     * Build an invalid dry-run response.
     *
     * Creates response indicating validation failed and mutation would not succeed.
     * Includes structured validation errors explaining what prevents execution.
     *
     * @param RequestObjectData                $request          Original request
     * @param array<int, array<string, mixed>> $validationErrors Validation error entries
     *
     * @return ResponseData Dry-run response with valid=false
     */
    public function buildInvalidResponse(
        RequestObjectData $request,
        array $validationErrors,
    ): ResponseData {
        return ResponseData::success(
            result: null,
            id: $request->id,
            extensions: [
                ExtensionData::response(ExtensionUrn::DryRun->value, [
                    'valid' => false,
                    'validation_errors' => $validationErrors,
                ]),
            ],
        );
    }

    /**
     * Build a validation error entry.
     *
     * Creates structured error with field path, error code, and message. Use for
     * invalid arguments, missing required fields, or constraint violations.
     *
     * @param string $field   JSON path to field with error (e.g., "user.email")
     * @param string $code    Error code (e.g., "required", "invalid_format")
     * @param string $message Human-readable error explanation
     *
     * @return array<string, string> Validation error structure
     */
    public function buildValidationError(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * Build a would-affect entry for single resource.
     *
     * Describes a specific resource that would be modified by the mutation.
     * Use when you can identify exact resource ID.
     *
     * @param string     $type   Resource type (e.g., "user", "post")
     * @param int|string $id     Resource identifier
     * @param string     $action Action that would be taken (e.g., "create", "update", "delete")
     *
     * @return array<string, mixed> Would-affect entry structure
     */
    public function buildWouldAffect(string $type, string|int $id, string $action): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'action' => $action,
        ];
    }

    /**
     * Build a would-affect entry for multiple resources.
     *
     * Describes a group of resources that would be modified. Use when mutation
     * affects multiple resources but individual IDs aren't practical to list.
     *
     * @param string $type   Resource type (e.g., "comment", "attachment")
     * @param int    $count  Number of resources that would be affected
     * @param string $action Action that would be taken (e.g., "delete", "archive")
     *
     * @return array<string, mixed> Would-affect entry structure
     */
    public function buildWouldAffectCount(string $type, int $count, string $action): array
    {
        return [
            'type' => $type,
            'count' => $count,
            'action' => $action,
        ];
    }

    /**
     * Build a side effect entry.
     *
     * Describes an operation that would be triggered as a side effect of the mutation,
     * such as webhooks, emails, or cascading updates.
     *
     * @param string $type  Side effect type (e.g., "webhook", "email", "notification")
     * @param string $event Event name or template (e.g., "user.created", "welcome_email")
     * @param int    $count Number of times this side effect would occur
     *
     * @return array<string, mixed> Side effect entry structure
     */
    public function buildSideEffect(string $type, string $event, int $count = 1): array
    {
        return [
            'type' => $type,
            'event' => $event,
            'count' => $count,
        ];
    }
}

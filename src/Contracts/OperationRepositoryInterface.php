<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Contracts;

use Cline\Forrst\Data\OperationData;

/**
 * Forrst async operation repository contract interface.
 *
 * Defines the contract for implementing persistent storage for async operations
 * created by the async extension. Repositories manage the complete lifecycle of
 * async operation state from creation through completion or cancellation.
 *
 * Provides CRUD operations for managing async operation state including status
 * tracking, result storage, and progress monitoring. Implementations must ensure
 * thread-safe operations and support concurrent access patterns.
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/extensions/async Async extension specification
 */
interface OperationRepositoryInterface
{
    /**
     * Find an operation by ID with access control.
     *
     * Retrieves a single operation by its unique identifier. Returns null if the
     * operation does not exist, has been deleted, or the user is unauthorized to access it.
     *
     * @param string      $id     Operation ID
     * @param null|string $userId User ID for access control (null = system access)
     *
     * @return null|OperationData The operation or null if not found or unauthorized
     */
    public function find(string $id, ?string $userId = null): ?OperationData;

    /**
     * Save an operation with ownership.
     *
     * Persists an operation to storage. If the operation already exists (based on ID),
     * it should be updated. If it does not exist, it should be created. Implementations
     * must handle concurrent updates safely.
     *
     * CONCURRENCY: Implementations MUST handle concurrent updates safely. Use optimistic
     * locking, row-level locks, or atomic operations to prevent race conditions when
     * multiple processes update the same operation simultaneously.
     *
     * @param OperationData $operation The operation to save
     * @param null|string   $userId    User ID to associate with operation
     */
    public function save(OperationData $operation, ?string $userId = null): void;

    /**
     * Delete an operation with access control.
     *
     * Removes an operation from storage by its ID. Idempotent - silently succeeds
     * if the operation does not exist. Implementations should verify user ownership
     * before deletion and throw an exception if unauthorized.
     *
     * @param string      $id     Operation ID
     * @param null|string $userId User ID for access control (null = system access)
     *
     * @throws \DomainException If user doesn't own the operation
     */
    public function delete(string $id, ?string $userId = null): void;

    /**
     * List operations with optional filters and access control.
     *
     * Retrieves a paginated list of operations matching the specified criteria.
     * Supports filtering by status and function name, with cursor-based pagination
     * for efficient traversal of large result sets.
     *
     * DATABASE INDEXES REQUIRED:
     * - (user_id, status, created_at) for user + status filtering
     * - (user_id, function, created_at) for user + function filtering
     * - (user_id, status, function, created_at) for combined filtering
     * - (created_at) for unfiltered queries
     *
     * @param null|string $status   Filter by status (pending, running, completed, failed, cancelled)
     * @param null|string $function Filter by function name
     * @param int         $limit    Maximum number of results to return (default: 50, max: 100)
     * @param null|string $cursor   Pagination cursor for fetching subsequent pages. Base64-encoded JSON
     *                               with format: {"last_id": "uuid", "last_created_at": "2024-01-01T00:00:00Z"}
     * @param null|string $userId   Filter by user ID (null = all operations, requires admin permissions)
     *
     * @return array{operations: array<int, OperationData>, next_cursor: ?string} Operations and next page cursor
     *
     * @throws \InvalidArgumentException If limit is less than 1 or greater than 100
     */
    public function list(
        ?string $status = null,
        ?string $function = null,
        int $limit = 50,
        ?string $cursor = null,
        ?string $userId = null,
    ): array;
}

# Code Review: AsyncExtension.php

## File Information
**Path:** `/Users/brian/Developer/cline/forrst/src/Extensions/Async/AsyncExtension.php`
**Purpose:** Handles long-running asynchronous function execution by decoupling request initiation from result delivery through operation tracking and polling.

---

## Executive Summary

AsyncExtension is a sophisticated implementation that enables asynchronous operation execution in the Forrst protocol. The class manages operation lifecycle (pending → processing → completed/failed/cancelled), provides polling mechanisms, and supports optional webhook callbacks. While the architecture is sound and the code is well-documented, there are several critical issues around thread safety, error handling, and potential race conditions that must be addressed before production use.

**Severity Breakdown:**
- Critical: 3 issues
- Major: 4 issues
- Minor: 3 issues
- Suggestions: 3 improvements

---

## SOLID Principles Analysis

### Single Responsibility Principle (SRP): GOOD
The class has a focused responsibility: managing async operation lifecycle. However, it does mix concerns slightly between operation creation, state transitions, and response building.

### Open/Closed Principle (OCP): PASS
The class extends AbstractExtension and can be extended further if needed, though it's marked `final` which prevents extension (this is intentional and appropriate).

### Liskov Substitution Principle (LSP): PASS
The class properly implements its parent and interfaces.

### Interface Segregation Principle (ISP): PASS
Implements `ProvidesFunctionsInterface` appropriately.

### Dependency Inversion Principle (DIP): EXCELLENT
Depends on `OperationRepositoryInterface` abstraction rather than concrete repository implementation, allowing flexibility in storage backends (database, Redis, etc.).

---

## Critical Issues

### Critical Issue #1: Race Condition in State Transitions (Lines 224-348)

**Location:** `markProcessing()`, `complete()`, `fail()`, `updateProgress()` methods
**Impact:** Multiple concurrent workers or requests could create race conditions when updating operation state. Without atomic updates or optimistic locking, operations could end up in inconsistent states.

**Problem Code:**
```php
public function markProcessing(string $operationId, ?float $progress = null): void
{
    $operation = $this->operations->find($operationId);  // Read

    if (!$operation instanceof OperationData) {
        return;
    }

    $updated = new OperationData(/* ... */);  // Modify

    $this->operations->save($updated);  // Write
}
```

**Why This Is Critical:**
Between the `find()` and `save()` calls, another process could modify the same operation. Consider this scenario:
1. Worker A reads operation (status: pending)
2. Worker B reads operation (status: pending)
3. Worker A updates to processing
4. Worker B updates to processing (overwriting A's changes)
5. Worker A completes operation
6. Worker B's later save overwrites the completion

**Solution:**
Implement optimistic locking with version numbers or use atomic compare-and-swap operations:

```php
// Update OperationData to include version field
<?php
namespace Cline\Forrst\Data;

final readonly class OperationData
{
    public function __construct(
        public string $id,
        public string $function,
        public string $version,
        public OperationStatus $status,
        public ?float $progress = null,
        public mixed $result = null,
        public ?array $errors = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $cancelledAt = null,
        public ?array $metadata = null,
        public int $operationVersion = 1,  // ADD THIS
    ) {}
}

// Update AsyncExtension methods to use optimistic locking
public function markProcessing(string $operationId, ?float $progress = null): void
{
    $maxRetries = 3;
    $attempt = 0;

    while ($attempt < $maxRetries) {
        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            return;
        }

        $updated = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: OperationStatus::Processing,
            progress: $progress,
            startedAt: now()->toImmutable(),
            metadata: $operation->metadata,
            operationVersion: $operation->operationVersion + 1,  // Increment version
        );

        // Repository must implement version-aware save
        $saved = $this->operations->saveIfVersionMatches(
            $updated,
            $operation->operationVersion
        );

        if ($saved) {
            return;  // Success
        }

        // Version mismatch, retry
        $attempt++;
        usleep(100000 * $attempt);  // Exponential backoff: 100ms, 200ms, 300ms
    }

    throw new \RuntimeException(sprintf(
        'Failed to mark operation %s as processing after %d attempts due to concurrent modifications',
        $operationId,
        $maxRetries
    ));
}

// Update OperationRepositoryInterface
<?php
namespace Cline\Forrst\Contracts;

interface OperationRepositoryInterface
{
    public function find(string $id): ?OperationData;

    public function save(OperationData $operation): void;

    /**
     * Save operation only if the current version matches expected version.
     *
     * @param OperationData $operation Operation to save
     * @param int $expectedVersion Expected current version in storage
     * @return bool True if saved, false if version mismatch
     */
    public function saveIfVersionMatches(OperationData $operation, int $expectedVersion): bool;
}
```

Apply this pattern to ALL state transition methods: `complete()`, `fail()`, `updateProgress()`.

---

### Critical Issue #2: Silent Failures in State Transitions (Lines 228-230, 256-260, 288-292, 321-325)

**Location:** All state transition methods
**Impact:** When an operation is not found, the methods silently return without any indication of failure. This makes debugging extremely difficult and could hide serious bugs.

**Problem Code:**
```php
public function complete(string $operationId, mixed $result): void
{
    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        return;  // SILENT FAILURE
    }

    // ...
}
```

**Why This Is Critical:**
1. Background workers won't know if they're trying to complete non-existent operations
2. Typos in operation IDs go undetected
3. Operation cleanup that deletes records while workers are still processing causes silent data loss
4. No logging or telemetry for troubleshooting

**Solution:**
```php
public function complete(string $operationId, mixed $result): void
{
    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        throw new OperationNotFoundException(sprintf(
            'Cannot complete operation %s: operation not found',
            $operationId
        ));
    }

    // Validate state transitions
    if ($operation->status === OperationStatus::Completed) {
        throw new InvalidOperationStateException(sprintf(
            'Cannot complete operation %s: already completed',
            $operationId
        ));
    }

    if ($operation->status === OperationStatus::Cancelled) {
        throw new InvalidOperationStateException(sprintf(
            'Cannot complete operation %s: operation was cancelled',
            $operationId
        ));
    }

    $updated = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: OperationStatus::Completed,
        progress: 1.0,
        result: $result,
        startedAt: $operation->startedAt,
        completedAt: now()->toImmutable(),
        metadata: $operation->metadata,
    );

    $this->operations->save($updated);
}

// Create custom exceptions
<?php
namespace Cline\Forrst\Extensions\Async\Exceptions;

final class OperationNotFoundException extends \RuntimeException
{
}

final class InvalidOperationStateException extends \RuntimeException
{
}
```

Apply this pattern to ALL state transition methods.

---

### Critical Issue #3: No Validation of Operation Metadata Size (Line 176)

**Location:** `createAsyncOperation()` method, line 176
**Impact:** Uncontrolled metadata can lead to storage bloat, memory issues, and potential denial-of-service if malicious clients send massive metadata payloads.

**Problem Code:**
```php
metadata: $metadata !== null ? array_merge($metadata, [
    'original_request_id' => $request->id,
    'callback_url' => $this->getCallbackUrl($extension->options),
]) : [
    'original_request_id' => $request->id,
    'callback_url' => $this->getCallbackUrl($extension->options),
],
```

**Why This Is Critical:**
- Malicious users could pass gigabytes of metadata
- Storage systems could run out of space
- Redis/memcached could hit memory limits
- Serialization/deserialization becomes expensive

**Solution:**
```php
private const int MAX_METADATA_SIZE_BYTES = 65536; // 64KB limit

public function createAsyncOperation(
    RequestObjectData $request,
    ExtensionData $extension,
    ?array $metadata = null,
    int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
): array {
    // Validate metadata size
    if ($metadata !== null) {
        $metadataJson = json_encode($metadata, JSON_THROW_ON_ERROR);
        $metadataSize = strlen($metadataJson);

        if ($metadataSize > self::MAX_METADATA_SIZE_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'Operation metadata size (%d bytes) exceeds maximum allowed (%d bytes)',
                $metadataSize,
                self::MAX_METADATA_SIZE_BYTES
            ));
        }
    }

    $systemMetadata = [
        'original_request_id' => $request->id,
        'callback_url' => $this->getCallbackUrl($extension->options),
        'created_at' => now()->toIso8601String(),
    ];

    $finalMetadata = $metadata !== null
        ? array_merge($metadata, $systemMetadata)
        : $systemMetadata;

    // Create the operation record
    $operation = new OperationData(
        id: $this->generateOperationId(),
        function: $request->call->function,
        version: $request->call->version,
        status: OperationStatus::Pending,
        metadata: $finalMetadata,
    );

    // ...
}
```

---

## Major Issues

### 🟠 Major Issue #1: Missing Callback URL Validation (Lines 137-144)

**Location:** `getCallbackUrl()` method
**Impact:** No validation of callback URLs creates security vulnerabilities (SSRF attacks) and operational issues (invalid URLs cause failures).

**Current Code:**
```php
public function getCallbackUrl(?array $options): ?string
{
    $callbackUrl = $options['callback_url'] ?? null;

    assert(is_string($callbackUrl) || $callbackUrl === null);

    return $callbackUrl;
}
```

**Solution:**
```php
private const array ALLOWED_CALLBACK_SCHEMES = ['https'];
private const array BLOCKED_CALLBACK_HOSTS = [
    'localhost',
    '127.0.0.1',
    '0.0.0.0',
    '169.254.169.254', // AWS metadata endpoint
    '::1',
    'metadata.google.internal', // GCP metadata
];

public function getCallbackUrl(?array $options): ?string
{
    $callbackUrl = $options['callback_url'] ?? null;

    if ($callbackUrl === null) {
        return null;
    }

    if (!is_string($callbackUrl)) {
        throw new \InvalidArgumentException(
            'Callback URL must be a string'
        );
    }

    // Validate URL format
    $parts = parse_url($callbackUrl);

    if ($parts === false) {
        throw new \InvalidArgumentException(sprintf(
            'Invalid callback URL format: %s',
            $callbackUrl
        ));
    }

    // Enforce HTTPS only
    if (!isset($parts['scheme']) || !in_array($parts['scheme'], self::ALLOWED_CALLBACK_SCHEMES, true)) {
        throw new \InvalidArgumentException(sprintf(
            'Callback URL must use HTTPS scheme, got: %s',
            $parts['scheme'] ?? 'none'
        ));
    }

    // Block internal/private IPs (SSRF protection)
    $host = $parts['host'] ?? '';

    if (in_array(strtolower($host), self::BLOCKED_CALLBACK_HOSTS, true)) {
        throw new \InvalidArgumentException(sprintf(
            'Callback URL host is not allowed: %s',
            $host
        ));
    }

    // Block private IP ranges
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new \InvalidArgumentException(sprintf(
                'Callback URL cannot use private/reserved IP addresses: %s',
                $host
            ));
        }
    }

    // Validate max URL length (prevent DoS)
    if (strlen($callbackUrl) > 2048) {
        throw new \InvalidArgumentException(
            'Callback URL exceeds maximum length of 2048 characters'
        );
    }

    return $callbackUrl;
}
```

---

### 🟠 Major Issue #2: Unbounded Progress Value in updateProgress (Line 338)

**Location:** `updateProgress()` method, line 338
**Impact:** While the code clamps progress using `max(0.0, min(1.0, $progress))`, it doesn't validate that progress values make sense (e.g., progress shouldn't decrease).

**Current Code:**
```php
progress: max(0.0, min(1.0, $progress)),
```

**Solution:**
```php
public function updateProgress(string $operationId, float $progress, ?string $message = null): void
{
    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        throw new OperationNotFoundException(sprintf(
            'Cannot update progress for operation %s: operation not found',
            $operationId
        ));
    }

    // Validate progress doesn't decrease
    $currentProgress = $operation->progress ?? 0.0;
    $newProgress = max(0.0, min(1.0, $progress));

    if ($newProgress < $currentProgress) {
        throw new \InvalidArgumentException(sprintf(
            'Progress cannot decrease from %.2f to %.2f for operation %s',
            $currentProgress,
            $newProgress,
            $operationId
        ));
    }

    // Validate operation is in a state where progress updates make sense
    if (!in_array($operation->status, [OperationStatus::Pending, OperationStatus::Processing], true)) {
        throw new InvalidOperationStateException(sprintf(
            'Cannot update progress for operation %s: operation is in %s state',
            $operationId,
            $operation->status->value
        ));
    }

    $metadata = $operation->metadata ?? [];

    if ($message !== null) {
        if (strlen($message) > 1000) {
            throw new \InvalidArgumentException(
                'Progress message cannot exceed 1000 characters'
            );
        }
        $metadata['progress_message'] = $message;
        $metadata['progress_updated_at'] = now()->toIso8601String();
    }

    $updated = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: $operation->status,
        progress: $newProgress,
        result: $operation->result,
        errors: $operation->errors,
        startedAt: $operation->startedAt,
        completedAt: $operation->completedAt,
        cancelledAt: $operation->cancelledAt,
        metadata: $metadata,
    );

    $this->operations->save($updated);
}
```

---

### 🟠 Major Issue #3: Missing Operation ID Collision Handling (Line 358)

**Location:** `generateOperationId()` method
**Impact:** While 96 bits of randomness makes collisions astronomically unlikely, there's no handling if a collision does occur. In distributed systems with millions of operations, checking for uniqueness is prudent.

**Current Code:**
```php
private function generateOperationId(): string
{
    return 'op_'.bin2hex(random_bytes(12));
}
```

**Solution:**
```php
private function generateOperationId(): string
{
    $maxAttempts = 10;

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $operationId = 'op_'.bin2hex(random_bytes(12));

        // Check if ID already exists
        $existing = $this->operations->find($operationId);

        if ($existing === null) {
            return $operationId;
        }

        // Collision detected (extremely rare), try again
    }

    throw new \RuntimeException(sprintf(
        'Failed to generate unique operation ID after %d attempts',
        $maxAttempts
    ));
}
```

---

### 🟠 Major Issue #4: No Operation Expiry/Cleanup Strategy (Throughout)

**Location:** General architectural concern
**Impact:** Operations accumulate indefinitely in the repository, leading to unbounded storage growth. Need TTL or cleanup mechanism.

**Solution:**
Add TTL support to operation data and repository:

```php
<?php
namespace Cline\Forrst\Data;

final readonly class OperationData
{
    public function __construct(
        public string $id,
        public string $function,
        public string $version,
        public OperationStatus $status,
        public ?float $progress = null,
        public mixed $result = null,
        public ?array $errors = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $cancelledAt = null,
        public ?array $metadata = null,
        public ?\DateTimeImmutable $expiresAt = null,  // ADD THIS
    ) {}

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return now()->greaterThan($this->expiresAt);
    }
}

// In AsyncExtension
private const int DEFAULT_OPERATION_TTL_HOURS = 24;

public function createAsyncOperation(
    RequestObjectData $request,
    ExtensionData $extension,
    ?array $metadata = null,
    int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
): array {
    $operation = new OperationData(
        id: $this->generateOperationId(),
        function: $request->call->function,
        version: $request->call->version,
        status: OperationStatus::Pending,
        metadata: $finalMetadata,
        expiresAt: now()->addHours(self::DEFAULT_OPERATION_TTL_HOURS)->toImmutable(),
    );

    // ...
}

// Add cleanup job that runs periodically
public function cleanupExpiredOperations(): int
{
    return $this->operations->deleteExpired();
}
```

---

## Minor Issues

### 🟡 Minor Issue #1: Magic Number for Random Bytes (Line 360)

**Location:** `generateOperationId()` method
**Impact:** The number 12 is hardcoded with no explanation of why 96 bits was chosen.

**Solution:**
```php
/**
 * Number of random bytes for operation ID (96 bits of entropy).
 *
 * Provides 2^96 possible IDs (~7.9 × 10^28), making collisions
 * astronomically unlikely even with billions of operations.
 */
private const int OPERATION_ID_BYTES = 12;

private function generateOperationId(): string
{
    return 'op_'.bin2hex(random_bytes(self::OPERATION_ID_BYTES));
}
```

---

### 🟡 Minor Issue #2: Inconsistent Metadata Handling (Lines 176-182 vs 327-331)

**Location:** `createAsyncOperation()` and `updateProgress()`
**Impact:** Metadata merging logic is duplicated and inconsistent about null handling.

**Solution:**
```php
/**
 * Merge user metadata with system metadata safely.
 *
 * @param array<string, mixed>|null $userMetadata User-provided metadata
 * @param array<string, mixed> $systemMetadata System-generated metadata
 * @return array<string, mixed> Merged metadata
 */
private function mergeMetadata(?array $userMetadata, array $systemMetadata): array
{
    if ($userMetadata === null) {
        return $systemMetadata;
    }

    // System metadata takes precedence over user metadata
    return array_merge($userMetadata, $systemMetadata);
}

// Usage in createAsyncOperation:
$operation = new OperationData(
    // ...
    metadata: $this->mergeMetadata($metadata, [
        'original_request_id' => $request->id,
        'callback_url' => $this->getCallbackUrl($extension->options),
    ]),
);
```

---

### 🟡 Minor Issue #3: Missing Documentation for Return Array Structure (Line 162)

**Location:** `createAsyncOperation()` return type
**Impact:** The array keys 'response' and 'operation' are not documented in the @return annotation.

**Solution:**
```php
/**
 * Create an async operation and build immediate response.
 *
 * Function handlers call this method when deciding to execute asynchronously.
 * It creates a pending operation record, persists it to the repository, and
 * returns both the immediate response to send to the client and the operation
 * record for background processing.
 *
 * The response includes polling instructions and retry timing to optimize
 * client polling behavior.
 *
 * @param RequestObjectData         $request      Original function call request
 * @param ExtensionData             $extension    Async extension data from request
 * @param null|array<string, mixed> $metadata     Optional metadata stored with operation
 * @param int                       $retrySeconds Suggested seconds between poll attempts
 *
 * @return array{
 *     response: ResponseData,
 *     operation: OperationData
 * } Response for client and operation record for worker
 *
 * @throws \InvalidArgumentException If metadata size exceeds limits
 * @throws \RuntimeException If operation cannot be persisted
 */
public function createAsyncOperation(/* ... */): array
{
    // ...
}
```

---

## Suggestions

### 🔵 Suggestion #1: Add Operation Metrics/Observability

**Benefit:** Track operation performance, failure rates, and queue depths for monitoring.

**Implementation:**
```php
final class AsyncExtension extends AbstractExtension implements ProvidesFunctionsInterface
{
    public function __construct(
        private readonly OperationRepositoryInterface $operations,
        private readonly ?MetricsInterface $metrics = null,
    ) {}

    public function complete(string $operationId, mixed $result): void
    {
        $startTime = microtime(true);
        $operation = $this->operations->find($operationId);

        // ... existing logic ...

        $this->operations->save($updated);

        // Record metrics
        if ($this->metrics !== null) {
            $duration = microtime(true) - $startTime;
            $this->metrics->histogram('async.operation.duration', $duration, [
                'function' => $operation->function,
                'status' => 'completed',
            ]);
            $this->metrics->increment('async.operation.completed', 1, [
                'function' => $operation->function,
            ]);
        }
    }
}
```

---

### 🔵 Suggestion #2: Add Webhook Notification Support

**Benefit:** Currently `callback_url` is stored but never used. Implement actual webhook delivery.

**Implementation:**
```php
private function notifyCallback(OperationData $operation): void
{
    $callbackUrl = $operation->metadata['callback_url'] ?? null;

    if ($callbackUrl === null) {
        return;
    }

    try {
        $payload = [
            'operation_id' => $operation->id,
            'status' => $operation->status->value,
            'result' => $operation->result,
            'errors' => $operation->errors,
            'completed_at' => $operation->completedAt?->toIso8601String(),
        ];

        // Use HTTP client to POST to callback URL
        $this->httpClient->post($callbackUrl, [
            'json' => $payload,
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Forrst-Async/1.0',
            ],
        ]);
    } catch (\Throwable $e) {
        // Log but don't fail the operation
        error_log(sprintf(
            'Failed to notify callback %s for operation %s: %s',
            $callbackUrl,
            $operation->id,
            $e->getMessage()
        ));
    }
}

public function complete(string $operationId, mixed $result): void
{
    // ... existing code ...

    $this->operations->save($updated);
    $this->notifyCallback($updated);
}
```

---

### 🔵 Suggestion #3: Add Operation Cancellation Support

**Benefit:** While there's an OperationCancelFunction, there's no `cancel()` method in AsyncExtension.

**Implementation:**
```php
/**
 * Cancel a pending or processing operation.
 *
 * @param string $operationId Operation to cancel
 * @throws OperationNotFoundException If operation doesn't exist
 * @throws InvalidOperationStateException If operation cannot be cancelled
 */
public function cancel(string $operationId): void
{
    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        throw new OperationNotFoundException(sprintf(
            'Cannot cancel operation %s: operation not found',
            $operationId
        ));
    }

    // Can only cancel pending or processing operations
    if (!in_array($operation->status, [OperationStatus::Pending, OperationStatus::Processing], true)) {
        throw new InvalidOperationStateException(sprintf(
            'Cannot cancel operation %s: operation is in %s state',
            $operationId,
            $operation->status->value
        ));
    }

    $updated = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: OperationStatus::Cancelled,
        progress: $operation->progress,
        startedAt: $operation->startedAt,
        cancelledAt: now()->toImmutable(),
        metadata: $operation->metadata,
    );

    $this->operations->save($updated);
    $this->notifyCallback($updated);
}
```

---

## Security Analysis

### High Severity

1. **SSRF Vulnerability (Callback URL):** Unvalidated callback URLs allow attackers to make the server request internal resources. See Major Issue #1.
2. **DoS via Metadata:** Unlimited metadata size allows resource exhaustion. See Critical Issue #3.

### Medium Severity

1. **Information Disclosure:** Operation IDs might leak information if the repository allows unauthorized access. Consider adding caller authentication/authorization.

---

## Performance Considerations

1. **Repository Query Efficiency:** Every state transition requires a `find()` + `save()`. Ensure repository implementations use indexed lookups.
2. **Progress Update Frequency:** Frequent `updateProgress()` calls could overwhelm the repository. Consider implementing rate limiting (max 1 update per second per operation).
3. **Metadata Serialization:** Large metadata requires expensive serialization. The added size limits help, but consider compression for large payloads.

---

## Testing Recommendations

```php
<?php

use PHPUnit\Framework\TestCase;

final class AsyncExtensionTest extends TestCase
{
    public function test_race_condition_handling(): void
    {
        // Test concurrent markProcessing calls
    }

    public function test_callback_url_ssrf_protection(): void
    {
        $extension = new AsyncExtension($this->createMock(OperationRepositoryInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $extension->getCallbackUrl(['callback_url' => 'http://169.254.169.254/metadata']);
    }

    public function test_metadata_size_limits(): void
    {
        // Test metadata exceeding 64KB
    }

    public function test_progress_cannot_decrease(): void
    {
        // Test that updateProgress rejects decreasing values
    }

    public function test_operation_expiry(): void
    {
        // Test that expired operations are cleaned up
    }
}
```

---

## Conclusion

AsyncExtension provides a solid foundation for asynchronous operation management, but requires critical improvements in:

1. **Concurrency control** - Add optimistic locking
2. **Error handling** - Replace silent failures with exceptions
3. **Security** - Validate callback URLs and metadata size
4. **Operation lifecycle** - Implement TTL and cleanup

These issues must be addressed before production deployment.

**Overall Grade: B-** (Good architecture but critical concurrency and security issues)

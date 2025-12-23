# Code Review: AsyncExtension.php

**File:** `/Users/brian/Developer/cline/forrst/src/Extensions/Async/AsyncExtension.php`
**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

The `AsyncExtension` enables long-running operations by decoupling request initiation from result delivery through polling-based async execution. The implementation is well-designed with clear separation of concerns, comprehensive documentation, and solid architectural patterns. However, it has **critical security vulnerabilities** related to input validation, race conditions in operation state transitions, and missing authorization controls that could enable DoS attacks and unauthorized access.

**Overall Assessment:** 🟠 **Requires Security Hardening Before Production**

### Severity Breakdown
- **Critical Issues:** 5 (Input validation, race conditions, operation ID enumeration, missing authorization, silent failures)
- **Major Issues:** 4 (TTL enforcement, webhook security, error handling, observability)
- **Minor Issues:** 3 (Magic numbers, type safety, documentation)

**Estimated Effort:**
- Critical fixes: 6-8 hours
- Major improvements: 4-6 hours
- Minor enhancements: 2-3 hours
- **Total: 12-17 hours**

---

## Critical Issues 🔴

### 1. No Input Validation on Operation IDs

**Location:** Lines 224-243, 254-275, 286-307, 319-348

**Issue:**
The `markProcessing()`, `complete()`, `fail()`, and `updateProgress()` methods accept operation IDs without validation. Attackers can:
- Inject arbitrary strings causing repository lookup failures
- Trigger errors in downstream systems
- Enumerate valid operation IDs through timing attacks

**Impact:**
- **Security:** Operation ID enumeration vulnerability
- **Reliability:** Invalid inputs cause silent failures
- **Debugging:** No logging when operations are not found

**Current Code:**
```php
public function markProcessing(string $operationId, ?float $progress = null): void
{
    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        return; // Silent failure
    }

    // ... rest of method
}
```

**Solution:**
```php
// Add at top of class after constants:
private const string OPERATION_ID_PREFIX = 'op_';
private const int OPERATION_ID_LENGTH = 27; // 'op_' + 24 hex chars

/**
 * Validate operation ID format.
 *
 * @param string $operationId Operation identifier to validate
 *
 * @throws \InvalidArgumentException If operation ID format is invalid
 */
private function validateOperationId(string $operationId): void
{
    if (strlen($operationId) !== self::OPERATION_ID_LENGTH) {
        throw new \InvalidArgumentException(
            sprintf('Invalid operation ID length: expected %d, got %d', self::OPERATION_ID_LENGTH, strlen($operationId))
        );
    }

    if (!str_starts_with($operationId, self::OPERATION_ID_PREFIX)) {
        throw new \InvalidArgumentException(
            sprintf('Invalid operation ID prefix: expected "%s"', self::OPERATION_ID_PREFIX)
        );
    }

    $hex = substr($operationId, strlen(self::OPERATION_ID_PREFIX));
    if (!ctype_xdigit($hex)) {
        throw new \InvalidArgumentException('Invalid operation ID: must contain only hexadecimal characters after prefix');
    }
}

// Update markProcessing():
public function markProcessing(string $operationId, ?float $progress = null): void
{
    $this->validateOperationId($operationId);

    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        throw new \RuntimeException("Operation not found: {$operationId}");
    }

    // ... rest of method
}

// Apply same validation to complete(), fail(), and updateProgress()
```

**Reference:** [OWASP Input Validation Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html)

---

### 2. Race Conditions in Operation State Transitions

**Location:** Lines 224-348

**Issue:**
All state update methods use check-then-act patterns without atomic operations:

```php
$operation = $this->operations->find($operationId);  // Read
// ... time passes, state could change ...
$this->operations->save($updated);  // Write
```

Between the read and write, another process could:
- Cancel the operation
- Complete the operation
- Update progress

This causes:
- Lost updates (last write wins)
- Invalid state transitions (pending → cancelled → processing)
- Inconsistent progress values

**Impact:**
- **Correctness:** Operations can end in invalid states
- **User Experience:** Clients see inconsistent status updates
- **Data Integrity:** Progress/results can be overwritten incorrectly

**Solution:**
```php
// Add to OperationRepositoryInterface:
interface OperationRepositoryInterface
{
    /**
     * Atomically update operation if current status matches expected.
     *
     * @param string $operationId Operation to update
     * @param OperationStatus $expectedStatus Current status that must match
     * @param OperationData $newData New operation data
     *
     * @return bool True if update succeeded, false if status didn't match
     */
    public function compareAndSwap(
        string $operationId,
        OperationStatus $expectedStatus,
        OperationData $newData
    ): bool;
}

// Update markProcessing() to use CAS:
public function markProcessing(string $operationId, ?float $progress = null): void
{
    $this->validateOperationId($operationId);

    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        throw new \RuntimeException("Operation not found: {$operationId}");
    }

    $updated = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: OperationStatus::Processing,
        progress: $progress,
        startedAt: now()->toImmutable(),
        metadata: $operation->metadata,
    );

    if (!$this->operations->compareAndSwap($operationId, OperationStatus::Pending, $updated)) {
        throw new \RuntimeException(
            "Cannot transition operation {$operationId} to processing: expected status Pending, got {$operation->status->value}"
        );
    }
}

// Apply similar CAS logic to complete(), fail(), and cancel operations
// Each should validate the expected current status before transitioning
```

**Reference:** [Database Concurrency Control](https://en.wikipedia.org/wiki/Optimistic_concurrency_control)

---

### 3. Operation ID Enumeration via Timing Attacks

**Location:** Lines 224-348

**Issue:**
Silent failures when operations don't exist create timing differences:
- Valid operation IDs: slower (repository lookup + processing)
- Invalid operation IDs: faster (early return)

Attackers can:
- Enumerate valid operation IDs through timing analysis
- Monitor other users' operations
- Cancel operations they don't own

**Impact:**
- **Security:** Information disclosure about active operations
- **Privacy:** Users can detect others' activity patterns
- **Authorization:** No ownership validation before state changes

**Solution:**
```php
// Add authorization to all operation methods:
private function validateOperationOwnership(OperationData $operation, string $requesterId): void
{
    $ownerId = $operation->metadata['owner_id'] ?? null;

    if ($ownerId === null) {
        throw new \RuntimeException('Operation has no owner metadata');
    }

    if ($ownerId !== $requesterId) {
        throw new \Cline\Forrst\Exceptions\UnauthorizedException(
            "Access denied: operation {$operation->id} belongs to different user"
        );
    }
}

// Update createAsyncOperation to store owner:
public function createAsyncOperation(
    RequestObjectData $request,
    ExtensionData $extension,
    ?array $metadata = null,
    int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
    string $ownerId, // ADD THIS PARAMETER
): array {
    $operation = new OperationData(
        id: $this->generateOperationId(),
        function: $request->call->function,
        version: $request->call->version,
        status: OperationStatus::Pending,
        metadata: $metadata !== null ? array_merge($metadata, [
            'original_request_id' => $request->id,
            'callback_url' => $this->getCallbackUrl($extension->options),
            'owner_id' => $ownerId, // ADD OWNER
        ]) : [
            'original_request_id' => $request->id,
            'callback_url' => $this->getCallbackUrl($extension->options),
            'owner_id' => $ownerId, // ADD OWNER
        ],
    );

    // ... rest of method
}

// Update markProcessing() to check ownership:
public function markProcessing(string $operationId, ?float $progress = null, string $requesterId): void
{
    $this->validateOperationId($operationId);

    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        // Use constant-time comparison to prevent timing attacks
        usleep(random_int(10000, 50000)); // 10-50ms random delay
        throw new \RuntimeException("Operation not found: {$operationId}");
    }

    $this->validateOperationOwnership($operation, $requesterId);

    // ... rest of method with CAS logic from issue #2
}

// Apply to all operation methods: complete(), fail(), updateProgress()
```

**Reference:** [Timing Attack Prevention](https://owasp.org/www-community/attacks/Timing_attack)

---

### 4. No Validation on Callback URLs

**Location:** Lines 137-144

**Issue:**
The `getCallbackUrl()` method accepts arbitrary URLs without validation:

```php
public function getCallbackUrl(?array $options): ?string
{
    $callbackUrl = $options['callback_url'] ?? null;
    assert(is_string($callbackUrl) || $callbackUrl === null);
    return $callbackUrl;
}
```

Attackers can:
- Specify internal network URLs (SSRF attacks)
- Use `file://` protocol to read local files
- Target localhost services (Redis, databases)
- Bypass firewall rules through server-side requests

**Impact:**
- **Security:** Server-Side Request Forgery (SSRF) vulnerability
- **Data Breach:** Access to internal services and files
- **Network Security:** Bypass of network segmentation

**Solution:**
```php
/**
 * Get and validate callback URL for completion notification.
 *
 * @param null|array<string, mixed> $options Extension options from request
 *
 * @return null|string Validated callback URL or null if not specified
 *
 * @throws \InvalidArgumentException If callback URL is invalid or insecure
 */
public function getCallbackUrl(?array $options): ?string
{
    $callbackUrl = $options['callback_url'] ?? null;

    if ($callbackUrl === null) {
        return null;
    }

    if (!is_string($callbackUrl)) {
        throw new \InvalidArgumentException('Callback URL must be a string');
    }

    // Validate URL format
    if (!filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
        throw new \InvalidArgumentException("Invalid callback URL format: {$callbackUrl}");
    }

    // Parse URL components
    $parts = parse_url($callbackUrl);

    if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
        throw new \InvalidArgumentException("Malformed callback URL: {$callbackUrl}");
    }

    // Only allow HTTPS (or HTTP for local development)
    $allowedSchemes = ['https'];
    if (app()->environment('local', 'testing')) {
        $allowedSchemes[] = 'http';
    }

    if (!in_array($parts['scheme'], $allowedSchemes, true)) {
        throw new \InvalidArgumentException(
            sprintf('Callback URL scheme must be one of: %s', implode(', ', $allowedSchemes))
        );
    }

    // Prevent SSRF attacks - block internal/private IPs
    $host = $parts['host'];

    // Block localhost/loopback
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
        throw new \InvalidArgumentException('Callback URL cannot target localhost');
    }

    // Block private IP ranges
    $ip = gethostbyname($host);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        throw new \InvalidArgumentException('Callback URL cannot target private/reserved IP ranges');
    }

    // Optional: maintain allowlist of permitted domains
    // $allowedDomains = config('forrst.async.allowed_callback_domains', []);
    // if (!empty($allowedDomains) && !in_array($host, $allowedDomains, true)) {
    //     throw new \InvalidArgumentException("Callback URL host not in allowlist: {$host}");
    // }

    return $callbackUrl;
}
```

Add configuration in `config/forrst.php`:
```php
'async' => [
    'allowed_callback_domains' => env('FORRST_ASYNC_CALLBACK_DOMAINS', null), // comma-separated list or null for any
    'callback_timeout' => env('FORRST_ASYNC_CALLBACK_TIMEOUT', 5), // seconds
    'callback_retries' => env('FORRST_ASYNC_CALLBACK_RETRIES', 3),
],
```

**Reference:** [OWASP SSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)

---

### 5. Silent Failures on Operation Not Found

**Location:** Lines 228-230, 258-260, 290-292, 323-325

**Issue:**
All operation methods silently return when operations aren't found:

```php
if (!$operation instanceof OperationData) {
    return; // No logging, no exception
}
```

This causes:
- Silent failures in background workers
- Missing operations never reported
- Difficult debugging when operations disappear
- Potential data loss if operation was deleted incorrectly

**Impact:**
- **Operations:** Failed operations never reported to monitoring
- **Debugging:** No audit trail when operations go missing
- **Reliability:** Workers continue silently despite failures

**Solution:**

Already addressed in Issue #1 and #3 solutions above by throwing exceptions instead of silent returns. Additionally, add logging:

```php
use Psr\Log\LoggerInterface;

final class AsyncExtension extends AbstractExtension implements ProvidesFunctionsInterface
{
    public function __construct(
        private readonly OperationRepositoryInterface $operations,
        private readonly LoggerInterface $logger, // ADD LOGGER DEPENDENCY
    ) {}

    public function markProcessing(string $operationId, ?float $progress = null, string $requesterId): void
    {
        $this->validateOperationId($operationId);

        $operation = $this->operations->find($operationId);

        if (!$operation instanceof OperationData) {
            $this->logger->error('Operation not found', [
                'operation_id' => $operationId,
                'requester_id' => $requesterId,
                'method' => 'markProcessing',
            ]);

            usleep(random_int(10000, 50000));
            throw new \RuntimeException("Operation not found: {$operationId}");
        }

        $this->validateOperationOwnership($operation, $requesterId);

        // Log successful state transition
        $this->logger->info('Operation marked as processing', [
            'operation_id' => $operationId,
            'progress' => $progress,
        ]);

        // ... rest of method
    }

    // Apply same logging pattern to complete(), fail(), updateProgress()
}
```

---

## Major Issues 🟠

### 6. No TTL/Retention Policy for Operations

**Location:** Lines 164-213 (createAsyncOperation)

**Issue:**
Operations are stored indefinitely without expiration. This causes:
- Unlimited storage growth
- Stale operation data never cleaned up
- Memory/disk exhaustion in high-traffic systems
- GDPR/privacy compliance issues

**Impact:**
- **Scalability:** Database/cache grows without bounds
- **Performance:** Queries slow as operation count increases
- **Compliance:** Retaining user data longer than necessary

**Solution:**
```php
// Add retention configuration:
private const int DEFAULT_OPERATION_TTL_SECONDS = 86400; // 24 hours
private const int MAX_OPERATION_TTL_SECONDS = 604800; // 7 days

// Update createAsyncOperation():
public function createAsyncOperation(
    RequestObjectData $request,
    ExtensionData $extension,
    ?array $metadata = null,
    int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
    string $ownerId,
    ?int $ttlSeconds = null, // ADD TTL PARAMETER
): array {
    // Validate and clamp TTL
    $ttl = $ttlSeconds ?? self::DEFAULT_OPERATION_TTL_SECONDS;
    $ttl = max(60, min($ttl, self::MAX_OPERATION_TTL_SECONDS)); // Min 1 minute, max 7 days

    $expiresAt = now()->addSeconds($ttl)->toImmutable();

    $operation = new OperationData(
        id: $this->generateOperationId(),
        function: $request->call->function,
        version: $request->call->version,
        status: OperationStatus::Pending,
        metadata: array_merge($metadata ?? [], [
            'original_request_id' => $request->id,
            'callback_url' => $this->getCallbackUrl($extension->options),
            'owner_id' => $ownerId,
            'expires_at' => $expiresAt->toIso8601String(), // ADD EXPIRATION
            'ttl_seconds' => $ttl,
        ]),
    );

    // Persist with TTL hint for cache-based repositories
    $this->operations->save($operation, $ttl);

    $this->logger->info('Async operation created', [
        'operation_id' => $operation->id,
        'function' => $operation->function,
        'ttl_seconds' => $ttl,
        'expires_at' => $expiresAt,
    ]);

    // ... rest of method
}

// Add cleanup command:
// php artisan make:command CleanupExpiredOperations

// In app/Console/Commands/CleanupExpiredOperations.php:
class CleanupExpiredOperations extends Command
{
    protected $signature = 'forrst:cleanup-operations {--dry-run}';
    protected $description = 'Remove expired async operations';

    public function handle(OperationRepositoryInterface $repository): int
    {
        $expiredBefore = now()->toImmutable();
        $deleted = $repository->deleteExpiredBefore($expiredBefore, $this->option('dry-run'));

        $this->info("Deleted {$deleted} expired operations");

        return self::SUCCESS;
    }
}

// Schedule in app/Console/Kernel.php:
protected function schedule(Schedule $schedule): void
{
    $schedule->command('forrst:cleanup-operations')
        ->hourly()
        ->withoutOverlapping();
}
```

**Reference:** [Data Retention Best Practices](https://gdpr.eu/data-retention/)

---

### 7. Webhook Callback Security Not Addressed

**Location:** Throughout (webhook functionality referenced but not implemented)

**Issue:**
While callback URLs are stored, there's no implementation showing:
- How callbacks are sent
- Authentication/signing of callback payloads
- Retry logic for failed callbacks
- Protection against callback loops

**Impact:**
- **Security:** Clients can't verify callbacks came from server
- **Reliability:** Failed callbacks lost forever
- **Performance:** No backoff for unreachable endpoints

**Solution:**
```php
// Create webhook service:
// In app/Services/AsyncWebhookService.php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AsyncWebhookService
{
    private const int MAX_RETRIES = 3;
    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Send webhook callback with HMAC signature.
     */
    public function sendCallback(string $url, OperationData $operation): void
    {
        $payload = [
            'operation_id' => $operation->id,
            'status' => $operation->status->value,
            'result' => $operation->result,
            'errors' => $operation->errors,
            'completed_at' => $operation->completedAt?->toIso8601String(),
        ];

        $secret = config('forrst.async.webhook_secret');
        $signature = $this->generateSignature($payload, $secret);

        $attempt = 0;
        $maxRetries = self::MAX_RETRIES;

        while ($attempt < $maxRetries) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders([
                        'X-Forrst-Signature' => $signature,
                        'X-Forrst-Operation-Id' => $operation->id,
                        'User-Agent' => 'Forrst-Async/1.0',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $this->logger->info('Webhook callback delivered', [
                        'operation_id' => $operation->id,
                        'url' => $url,
                        'attempt' => $attempt + 1,
                    ]);
                    return;
                }

                $this->logger->warning('Webhook callback failed', [
                    'operation_id' => $operation->id,
                    'url' => $url,
                    'status' => $response->status(),
                    'attempt' => $attempt + 1,
                ]);

            } catch (\Exception $e) {
                $this->logger->error('Webhook callback exception', [
                    'operation_id' => $operation->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt + 1,
                ]);
            }

            $attempt++;
            if ($attempt < $maxRetries) {
                // Exponential backoff: 1s, 2s, 4s
                sleep(2 ** $attempt);
            }
        }

        $this->logger->error('Webhook callback failed after retries', [
            'operation_id' => $operation->id,
            'url' => $url,
            'attempts' => $maxRetries,
        ]);
    }

    /**
     * Generate HMAC signature for webhook payload.
     */
    private function generateSignature(array $payload, string $secret): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $json, $secret);
    }
}

// Update complete() and fail() methods to trigger webhooks:
public function complete(string $operationId, mixed $result, string $requesterId): void
{
    // ... existing validation and update logic ...

    $callbackUrl = $updated->metadata['callback_url'] ?? null;
    if (is_string($callbackUrl)) {
        dispatch(fn() => app(AsyncWebhookService::class)->sendCallback($callbackUrl, $updated))
            ->afterResponse();
    }
}
```

Add to `config/forrst.php`:
```php
'async' => [
    'webhook_secret' => env('FORRST_ASYNC_WEBHOOK_SECRET', Str::random(32)),
    // ... other config
],
```

**Reference:** [Webhook Security Best Practices](https://webhooks.fyi/security/hmac)

---

### 8. Progress Value Not Validated

**Location:** Line 338

**Issue:**
While progress is clamped to [0.0, 1.0], there's no validation that:
- Progress never decreases (unless restarting)
- Progress makes sense for operation status
- Multiple workers don't conflict on progress updates

**Solution:**
```php
public function updateProgress(
    string $operationId,
    float $progress,
    ?string $message = null,
    string $requesterId
): void {
    $this->validateOperationId($operationId);

    $operation = $this->operations->find($operationId);

    if (!$operation instanceof OperationData) {
        $this->logger->error('Operation not found for progress update', [
            'operation_id' => $operationId,
        ]);
        usleep(random_int(10000, 50000));
        throw new \RuntimeException("Operation not found: {$operationId}");
    }

    $this->validateOperationOwnership($operation, $requesterId);

    // Validate operation is in valid state for progress updates
    if (!in_array($operation->status, [OperationStatus::Pending, OperationStatus::Processing], true)) {
        throw new \InvalidArgumentException(
            "Cannot update progress for operation in {$operation->status->value} status"
        );
    }

    // Clamp and validate progress
    $newProgress = max(0.0, min(1.0, $progress));

    // Ensure progress doesn't decrease (with small tolerance for floating point)
    $currentProgress = $operation->progress ?? 0.0;
    if ($newProgress < $currentProgress - 0.001) {
        $this->logger->warning('Progress update attempted to decrease', [
            'operation_id' => $operationId,
            'current_progress' => $currentProgress,
            'new_progress' => $newProgress,
        ]);
        throw new \InvalidArgumentException(
            "Progress cannot decrease: current={$currentProgress}, new={$newProgress}"
        );
    }

    $metadata = $operation->metadata ?? [];

    if ($message !== null) {
        $metadata['progress_message'] = $message;
        $metadata['progress_updated_at'] = now()->toIso8601String();
    }

    $updated = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: $operation->status === OperationStatus::Pending ? OperationStatus::Processing : $operation->status,
        progress: $newProgress,
        result: $operation->result,
        errors: $operation->errors,
        startedAt: $operation->startedAt ?? ($operation->status === OperationStatus::Pending ? now()->toImmutable() : null),
        completedAt: $operation->completedAt,
        cancelledAt: $operation->cancelledAt,
        metadata: $metadata,
    );

    // Use CAS to prevent race conditions
    if (!$this->operations->compareAndSwap($operationId, $operation->status, $updated)) {
        throw new \RuntimeException(
            "Progress update failed due to status change: operation {$operationId}"
        );
    }

    $this->logger->debug('Progress updated', [
        'operation_id' => $operationId,
        'progress' => $newProgress,
        'message' => $message,
    ]);
}
```

---

### 9. No Rate Limiting on Operation Creation

**Location:** Lines 164-213

**Issue:**
Clients can create unlimited async operations, causing:
- Resource exhaustion
- Repository/cache overflow
- Background worker overload

**Impact:**
- **Availability:** DoS through operation spam
- **Cost:** Excessive storage/compute usage
- **Performance:** System degradation under load

**Solution:**
```php
use Illuminate\Support\Facades\RateLimiter;

public function createAsyncOperation(
    RequestObjectData $request,
    ExtensionData $extension,
    ?array $metadata = null,
    int $retrySeconds = self::DEFAULT_RETRY_SECONDS,
    string $ownerId,
    ?int $ttlSeconds = null,
): array {
    // Rate limit operation creation per user
    $rateLimitKey = "async_operations:{$ownerId}";

    if (!RateLimiter::attempt($rateLimitKey, $maxAttempts = 100, fn() => true, $decaySeconds = 60)) {
        throw new \Cline\Forrst\Exceptions\RateLimitException(
            'Too many async operations created. Please try again later.'
        );
    }

    // Also check concurrent operation limit
    $activeCount = $this->operations->countActiveByOwner($ownerId);
    $maxConcurrent = config('forrst.async.max_concurrent_operations', 10);

    if ($activeCount >= $maxConcurrent) {
        throw new \Cline\Forrst\Exceptions\QuotaExceededException(
            "Maximum concurrent operations limit reached: {$maxConcurrent}"
        );
    }

    // ... rest of existing method
}
```

Add to `config/forrst.php`:
```php
'async' => [
    'max_concurrent_operations' => env('FORRST_ASYNC_MAX_CONCURRENT', 10),
    'rate_limit_per_minute' => env('FORRST_ASYNC_RATE_LIMIT', 100),
    // ... other config
],
```

---

## Minor Issues 🟡

### 10. Magic Number for Operation ID Length

**Location:** Line 360

**Issue:**
The operation ID uses 12 bytes hardcoded without explanation of why 12.

**Solution:**
```php
// Replace at top of class:
/**
 * Number of random bytes for operation ID (96 bits of entropy).
 * Provides ~10^28 unique IDs before birthday collision at 50% probability.
 */
private const int OPERATION_ID_BYTES = 12;

// Update generateOperationId():
private function generateOperationId(): string
{
    return self::OPERATION_ID_PREFIX.bin2hex(random_bytes(self::OPERATION_ID_BYTES));
}
```

---

### 11. Missing Type Hints on Metadata

**Location:** Lines 167, 176, 327

**Issue:**
Metadata is typed as `?array` but could be more specific about allowed values.

**Solution:**
```php
// Create value object for operation metadata:
readonly class OperationMetadata
{
    /**
     * @param string $originalRequestId Original request ID that created this operation
     * @param null|string $callbackUrl URL for completion webhook
     * @param string $ownerId User/service that owns this operation
     * @param \DateTimeImmutable $expiresAt When operation should be purged
     * @param int $ttlSeconds TTL in seconds
     * @param null|string $progressMessage Human-readable progress status
     * @param null|string $progressUpdatedAt ISO8601 timestamp of last progress update
     * @param array<string, mixed> $custom Additional custom metadata
     */
    public function __construct(
        public string $originalRequestId,
        public ?string $callbackUrl,
        public string $ownerId,
        public \DateTimeImmutable $expiresAt,
        public int $ttlSeconds,
        public ?string $progressMessage = null,
        public ?string $progressUpdatedAt = null,
        public array $custom = [],
    ) {}

    public function toArray(): array
    {
        return [
            'original_request_id' => $this->originalRequestId,
            'callback_url' => $this->callbackUrl,
            'owner_id' => $this->ownerId,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'ttl_seconds' => $this->ttlSeconds,
            'progress_message' => $this->progressMessage,
            'progress_updated_at' => $this->progressUpdatedAt,
            ...$this->custom,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            originalRequestId: $data['original_request_id'],
            callbackUrl: $data['callback_url'] ?? null,
            ownerId: $data['owner_id'],
            expiresAt: new \DateTimeImmutable($data['expires_at']),
            ttlSeconds: $data['ttl_seconds'],
            progressMessage: $data['progress_message'] ?? null,
            progressUpdatedAt: $data['progress_updated_at'] ?? null,
            custom: array_diff_key($data, array_flip([
                'original_request_id', 'callback_url', 'owner_id',
                'expires_at', 'ttl_seconds', 'progress_message', 'progress_updated_at',
            ])),
        );
    }
}
```

---

### 12. Inconsistent Error Handling Between Methods

**Location:** Throughout

**Issue:**
Some methods use assertions, others use silent returns, none throw consistent exceptions.

**Solution:**
Already addressed in critical issues above. Ensure all methods:
- Validate inputs and throw `InvalidArgumentException`
- Throw `RuntimeException` for operation not found
- Throw `UnauthorizedException` for ownership violations
- Log all errors before throwing

---

## Architecture & Design Patterns

### Strengths

1. **Clean Separation of Concerns**
   - Extension handles orchestration
   - Repository handles persistence
   - Clear interface contracts

2. **Well-Designed API**
   - Intuitive method names
   - Clear parameter documentation
   - Sensible defaults

3. **Comprehensive Documentation**
   - Excellent class-level PHPDoc
   - Parameter descriptions for all methods
   - Usage examples in comments

4. **Flexible Design**
   - Repository interface allows multiple implementations
   - Metadata extensibility for custom use cases
   - Optional webhooks vs polling

### Architectural Recommendations

1. **Add Domain Events**
   ```php
   // Dispatch events for monitoring:
   event(new OperationCreated($operation));
   event(new OperationCompleted($operation));
   event(new OperationFailed($operation, $errors));
   event(new OperationCancelled($operation));
   ```

2. **Implement Observer Pattern for Progress**
   ```php
   interface OperationObserver
   {
       public function onProgress(OperationData $operation, float $progress): void;
       public function onStatusChange(OperationData $operation, OperationStatus $oldStatus): void;
   }
   ```

3. **Add Metrics Collection**
   ```php
   // Track operation metrics:
   Metrics::increment('async.operations.created');
   Metrics::increment('async.operations.completed');
   Metrics::histogram('async.operation.duration', $duration);
   Metrics::gauge('async.operations.active', $activeCount);
   ```

---

## Testing Recommendations

### Critical Test Cases

1. **Security Tests**
   ```php
   test('rejects operation IDs with invalid format', function() {
       $extension = new AsyncExtension($repo, $logger);

       expect(fn() => $extension->markProcessing('invalid-id', null, 'user-1'))
           ->toThrow(InvalidArgumentException::class);
   });

   test('prevents SSRF via callback URL', function() {
       $options = ['callback_url' => 'http://localhost:6379/'];

       expect(fn() => $extension->getCallbackUrl($options))
           ->toThrow(InvalidArgumentException::class, 'localhost');
   });

   test('prevents operation access by non-owner', function() {
       $operation = createOperation(['owner_id' => 'user-1']);

       expect(fn() => $extension->complete($operation->id, 'result', 'user-2'))
           ->toThrow(UnauthorizedException::class);
   });
   ```

2. **Race Condition Tests**
   ```php
   test('prevents concurrent state transitions', function() {
       $operation = createOperation(OperationStatus::Pending);

       // Simulate two workers trying to mark processing simultaneously
       $worker1 = fn() => $extension->markProcessing($operation->id, null, 'user-1');
       $worker2 = fn() => $extension->markProcessing($operation->id, null, 'user-1');

       // One should succeed, one should fail
       parallel([$worker1, $worker2], function($results) {
           $exceptions = array_filter($results, fn($r) => $r instanceof Exception);
           expect($exceptions)->toHaveCount(1);
       });
   });
   ```

3. **TTL Tests**
   ```php
   test('expires operations after TTL', function() {
       $operation = createOperationWithTtl(ttl: 1); // 1 second

       sleep(2);

       Artisan::call('forrst:cleanup-operations');

       expect($repo->find($operation->id))->toBeNull();
   });
   ```

---

## Summary

The `AsyncExtension` provides a well-designed foundation for async operation handling with excellent documentation and clean architecture. However, it requires significant security hardening before production use:

### Must Fix Before Production
1. Add input validation for operation IDs
2. Implement atomic state transitions (CAS)
3. Add operation ownership/authorization
4. Validate and sanitize callback URLs
5. Replace silent failures with exceptions and logging

### Should Fix Soon
6. Add TTL/retention policy for operations
7. Implement secure webhook delivery
8. Add progress validation rules
9. Implement rate limiting on operation creation

### Consider Fixing
10. Extract magic numbers to constants
11. Add type-safe metadata handling
12. Standardize error handling patterns

**Estimated Total Effort: 12-17 hours**

The architectural foundation is solid. Focus on security and reliability improvements will make this production-ready.

---

**Files Referenced:**
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/AsyncExtension.php`
- `/Users/brian/Developer/cline/forrst/src/Contracts/OperationRepositoryInterface.php` (interface updates needed)
- `/Users/brian/Developer/cline/forrst/config/forrst.php` (configuration additions needed)
- `/Users/brian/Developer/cline/forrst/app/Services/AsyncWebhookService.php` (new service needed)
- `/Users/brian/Developer/cline/forrst/app/Console/Commands/CleanupExpiredOperations.php` (new command needed)

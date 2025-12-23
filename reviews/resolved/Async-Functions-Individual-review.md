# Code Review: Async Functions (3 files)

**Files Reviewed:**
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationCancelFunction.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationListFunction.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationStatusFunction.php`

**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

These function implementations handle async operation management (cancel, list, status). The code is clean and well-structured but has **critical security vulnerabilities** around authorization - none of the functions validate that the requester owns the operations they're accessing. Additionally, there are race conditions in the cancel operation and missing input validation.

**Overall Assessment:** 🔴 **Critical Security Issues - Not Production Ready**

### Severity Breakdown
- **Critical Issues:** 3 (Missing authorization, race conditions, information disclosure)
- **Major Issues:** 3 (Input validation, error handling, logging)
- **Minor Issues:** 2 (Type safety, performance)

**Estimated Effort:**
- Critical fixes: 4-6 hours
- Major improvements: 2-3 hours
- Minor enhancements: 1-2 hours
- **Total: 7-11 hours**

---

## Critical Issues 🔴

### 1. No Authorization - Users Can Access Any Operation

**Location:** All three files

**Issue:**
**CRITICAL SECURITY VULNERABILITY**: None of the functions verify that the requesting user owns the operation they're accessing. Any authenticated user can:
- Check status of operations belonging to other users
- Cancel operations they don't own
- List all operations in the system (OperationListFunction.php line 61)

**OperationCancelFunction.php** lines 54-64:
```php
public function __invoke(): array
{
    $operationId = $this->requestObject->getArgument('operation_id');
    assert(is_string($operationId));

    $operation = $this->repository->find($operationId);
    // NO AUTHORIZATION CHECK HERE

    if (!$operation instanceof OperationData) {
        throw OperationNotFoundException::create($operationId);
    }
```

**OperationListFunction.php** lines 49-61:
```php
public function __invoke(): array
{
    // Filters but NO ownership filtering
    $result = $this->repository->list($status, $function, $limit, $cursor);
    // Returns ALL operations, not just requester's operations
```

**Impact:**
- **Privacy Violation:** Users can spy on other users' operations
- **Data Breach:** Sensitive operation results exposed
- **Security:** Users can disrupt other users by canceling their operations

**Solution:**

Add authorization to all functions. First, update the repository interface to accept owner ID:

```php
// In OperationRepositoryInterface:
interface OperationRepositoryInterface
{
    public function find(string $operationId, ?string $ownerId = null): ?OperationData;

    public function list(
        ?string $status,
        ?string $function,
        int $limit,
        ?string $cursor,
        string $ownerId // ADD OWNER FILTER
    ): array;
}
```

Then update **OperationCancelFunction.php**:

```php
use Cline\Forrst\Exceptions\UnauthorizedException;
use Psr\Log\LoggerInterface;

final class OperationCancelFunction extends AbstractFunction
{
    public function __construct(
        private readonly OperationRepositoryInterface $repository,
        private readonly LoggerInterface $logger, // ADD LOGGER
    ) {}

    public function __invoke(): array
    {
        $operationId = $this->requestObject->getArgument('operation_id');
        assert(is_string($operationId));

        // Get authenticated user/requester ID
        $requesterId = $this->getRequesterId();

        // Find with ownership check
        $operation = $this->repository->find($operationId, $requesterId);

        if (!$operation instanceof OperationData) {
            // Use constant-time response to prevent enumeration
            usleep(random_int(10000, 50000));

            $this->logger->warning('Operation not found or unauthorized', [
                'operation_id' => $operationId,
                'requester_id' => $requesterId,
            ]);

            throw OperationNotFoundException::create($operationId);
        }

        // Verify ownership explicitly
        $this->validateOwnership($operation, $requesterId);

        if ($operation->isTerminal()) {
            throw OperationCannotCancelException::create($operationId, $operation->status);
        }

        $now = CarbonImmutable::now();
        $cancelledOperation = new OperationData(
            id: $operation->id,
            function: $operation->function,
            version: $operation->version,
            status: OperationStatus::Cancelled,
            progress: $operation->progress,
            result: $operation->result,
            errors: $operation->errors,
            startedAt: $operation->startedAt,
            completedAt: $operation->completedAt,
            cancelledAt: $now,
            metadata: $operation->metadata,
        );

        $this->repository->save($cancelledOperation);

        $this->logger->info('Operation cancelled', [
            'operation_id' => $operationId,
            'requester_id' => $requesterId,
        ]);

        return [
            'operation_id' => $operationId,
            'status' => 'cancelled',
            'cancelled_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Get requester ID from request context.
     */
    private function getRequesterId(): string
    {
        // This depends on your auth implementation
        // Examples:
        // return $this->requestObject->getAuthenticatedUserId();
        // return auth()->id();
        // return $this->requestObject->getMetadata('user_id');

        $requesterId = $this->requestObject->getAuthenticatedUserId();

        if ($requesterId === null) {
            throw new UnauthorizedException('Authentication required');
        }

        return $requesterId;
    }

    /**
     * Validate that requester owns the operation.
     */
    private function validateOwnership(OperationData $operation, string $requesterId): void
    {
        $ownerId = $operation->metadata['owner_id'] ?? null;

        if ($ownerId === null) {
            $this->logger->error('Operation missing owner metadata', [
                'operation_id' => $operation->id,
            ]);
            throw new \RuntimeException('Operation has invalid ownership metadata');
        }

        if ($ownerId !== $requesterId) {
            $this->logger->warning('Unauthorized operation access attempt', [
                'operation_id' => $operation->id,
                'owner_id' => $ownerId,
                'requester_id' => $requesterId,
            ]);

            // Throw same exception as not found to prevent enumeration
            throw OperationNotFoundException::create($operation->id);
        }
    }
}
```

Apply similar changes to **OperationStatusFunction.php** (add same helper methods and authorization).

For **OperationListFunction.php**:

```php
use Psr\Log\LoggerInterface;

final class OperationListFunction extends AbstractFunction
{
    public function __construct(
        private readonly OperationRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(): array
    {
        $status = $this->requestObject->getArgument('status');
        $function = $this->requestObject->getArgument('function');
        $limit = $this->requestObject->getArgument('limit', 50);
        $cursor = $this->requestObject->getArgument('cursor');

        assert(is_string($status) || $status === null);
        assert(is_string($function) || $function === null);
        assert(is_int($limit));
        assert(is_string($cursor) || $cursor === null);

        // Get authenticated user
        $requesterId = $this->getRequesterId();

        // CRITICAL: Filter by owner
        $result = $this->repository->list(
            $status,
            $function,
            $limit,
            $cursor,
            $requesterId // ADD OWNER FILTER
        );

        $this->logger->debug('Operations listed', [
            'requester_id' => $requesterId,
            'count' => count($result['operations']),
            'status_filter' => $status,
        ]);

        $response = [
            'operations' => array_map(
                fn (OperationData $op): array => $op->toArray(),
                $result['operations'],
            ),
        ];

        if ($result['next_cursor'] !== null) {
            $response['next_cursor'] = $result['next_cursor'];
        }

        return $response;
    }

    // Add same getRequesterId() helper as in OperationCancelFunction
    private function getRequesterId(): string
    {
        $requesterId = $this->requestObject->getAuthenticatedUserId();

        if ($requesterId === null) {
            throw new UnauthorizedException('Authentication required');
        }

        return $requesterId;
    }
}
```

**Reference:** [OWASP Broken Access Control](https://owasp.org/Top10/A01_2021-Broken_Access_Control/)

---

### 2. Race Condition in Operation Cancellation

**Location:** OperationCancelFunction.php lines 66-85

**Issue:**
The cancel operation uses a check-then-act pattern:

```php
if ($operation->isTerminal()) {
    throw OperationCannotCancelException::create($operationId, $operation->status);
}

// ... time passes, operation could complete here ...

$this->repository->save($cancelledOperation);
```

Between the terminal status check and save, another process could:
- Complete the operation
- Fail the operation
- Cancel the operation

This results in:
- Lost completion results
- Incorrect final states
- Data corruption

**Impact:**
- **Data Integrity:** Operation final states can be wrong
- **User Experience:** Completed operations shown as cancelled
- **Results Lost:** Successful results discarded

**Solution:**

```php
public function __invoke(): array
{
    $operationId = $this->requestObject->getArgument('operation_id');
    assert(is_string($operationId));

    $requesterId = $this->getRequesterId();
    $operation = $this->repository->find($operationId, $requesterId);

    if (!$operation instanceof OperationData) {
        usleep(random_int(10000, 50000));
        throw OperationNotFoundException::create($operationId);
    }

    $this->validateOwnership($operation, $requesterId);

    if ($operation->isTerminal()) {
        throw OperationCannotCancelException::create($operationId, $operation->status);
    }

    $now = CarbonImmutable::now();
    $cancelledOperation = new OperationData(
        id: $operation->id,
        function: $operation->function,
        version: $operation->version,
        status: OperationStatus::Cancelled,
        progress: $operation->progress,
        result: $operation->result,
        errors: $operation->errors,
        startedAt: $operation->startedAt,
        completedAt: $operation->completedAt,
        cancelledAt: $now,
        metadata: $operation->metadata,
    );

    // Use compare-and-swap to prevent race conditions
    $expectedStatuses = [OperationStatus::Pending, OperationStatus::Processing];

    $success = $this->repository->compareAndSwap(
        $operationId,
        $expectedStatuses,
        $cancelledOperation
    );

    if (!$success) {
        // Re-fetch to get current status
        $current = $this->repository->find($operationId, $requesterId);

        if ($current && $current->isTerminal()) {
            throw OperationCannotCancelException::create($operationId, $current->status);
        }

        throw new \RuntimeException("Failed to cancel operation {$operationId} due to concurrent modification");
    }

    $this->logger->info('Operation cancelled', [
        'operation_id' => $operationId,
        'requester_id' => $requesterId,
    ]);

    return [
        'operation_id' => $operationId,
        'status' => 'cancelled',
        'cancelled_at' => $now->toIso8601String(),
    ];
}
```

Update `OperationRepositoryInterface`:

```php
interface OperationRepositoryInterface
{
    /**
     * Atomically update operation if status matches expected value(s).
     *
     * @param string $operationId Operation to update
     * @param array<OperationStatus>|OperationStatus $expectedStatus Current status(es) that must match
     * @param OperationData $newData New operation data
     *
     * @return bool True if update succeeded, false if status didn't match
     */
    public function compareAndSwap(
        string $operationId,
        array|OperationStatus $expectedStatus,
        OperationData $newData
    ): bool;
}
```

**Reference:** [Optimistic Concurrency Control](https://en.wikipedia.org/wiki/Optimistic_concurrency_control)

---

### 3. Operation ID Timing Attack Vulnerability

**Location:**
- OperationCancelFunction.php lines 60-64
- OperationStatusFunction.php lines 56-60

**Issue:**
Finding operations has different timing:
- Valid operation: database lookup + processing time
- Invalid operation: fast exception

Attackers can enumerate valid operation IDs through timing analysis.

**Impact:**
- **Privacy:** Attackers can discover which operations exist
- **Security:** Enables targeted attacks on specific operations
- **Information Disclosure:** Leak of operation existence

**Solution:**

Already addressed in Solution #1 above with the constant-time delay:

```php
if (!$operation instanceof OperationData) {
    // Add random delay to prevent timing attacks
    usleep(random_int(10000, 50000)); // 10-50ms
    throw OperationNotFoundException::create($operationId);
}
```

Apply this to both OperationCancelFunction and OperationStatusFunction.

---

## Major Issues 🟠

### 4. No Input Validation on Operation ID

**Location:** All three files

**Issue:**
Operation IDs accepted without format validation:

```php
$operationId = $this->requestObject->getArgument('operation_id');
assert(is_string($operationId)); // Only checks type, not format
```

Invalid operation IDs cause unnecessary repository lookups.

**Solution:**

```php
private function validateOperationId(string $operationId): void
{
    if (!preg_match('/^op_[0-9a-f]{24}$/', $operationId)) {
        throw new \InvalidArgumentException(
            "Invalid operation ID format: {$operationId}. Expected format: op_<24 hex characters>"
        );
    }
}

// In __invoke():
$operationId = $this->requestObject->getArgument('operation_id');
assert(is_string($operationId));
$this->validateOperationId($operationId); // ADD VALIDATION
```

Add this helper to all three function classes.

---

### 5. Missing Observability/Logging

**Location:** All files (except partially addressed in solutions above)

**Issue:**
No logging for:
- Successful operations
- Failed authorization attempts
- Performance metrics
- Usage patterns

**Impact:**
- **Operations:** Can't monitor API usage
- **Security:** Can't detect abuse patterns
- **Debugging:** No audit trail

**Solution:**

Already addressed in Critical Issue #1 solutions. Ensure comprehensive logging:

```php
// Log successful operations
$this->logger->info('Operation status checked', [
    'operation_id' => $operationId,
    'requester_id' => $requesterId,
    'status' => $operation->status->value,
]);

// Log failures
$this->logger->warning('Unauthorized access attempt', [
    'operation_id' => $operationId,
    'requester_id' => $requesterId,
    'action' => 'status_check',
]);

// Log performance
$this->logger->debug('List operation completed', [
    'requester_id' => $requesterId,
    'result_count' => count($operations),
    'query_time_ms' => $elapsed,
]);
```

---

### 6. No Rate Limiting

**Location:** All three functions

**Issue:**
Users can poll status or list operations unlimited times, causing:
- Repository overload
- DoS through excessive polling
- Unnecessary resource consumption

**Solution:**

```php
use Illuminate\Support\Facades\RateLimiter;

public function __invoke(): array
{
    $operationId = $this->requestObject->getArgument('operation_id');
    $requesterId = $this->getRequesterId();

    // Rate limit status checks per operation
    $rateLimitKey = "operation_status:{$requesterId}:{$operationId}";

    if (!RateLimiter::attempt($rateLimitKey, 60, fn() => true, 60)) {
        throw new \Cline\Forrst\Exceptions\RateLimitException(
            'Too many status checks. Please wait before polling again.'
        );
    }

    // ... rest of method
}
```

For list function:

```php
public function __invoke(): array
{
    $requesterId = $this->getRequesterId();

    // Rate limit list operations per user
    $rateLimitKey = "operation_list:{$requesterId}";

    if (!RateLimiter::attempt($rateLimitKey, 30, fn() => true, 60)) {
        throw new \Cline\Forrst\Exceptions\RateLimitException(
            'Too many list requests. Please wait before trying again.'
        );
    }

    // ... rest of method
}
```

**Reference:** [API Rate Limiting Best Practices](https://cloud.google.com/architecture/rate-limiting-strategies-techniques)

---

## Minor Issues 🟡

### 7. Assertions Instead of Validation

**Location:** All files

**Issue:**
Using `assert()` for type checking instead of proper validation:

```php
assert(is_string($operationId));
```

Assertions can be disabled in production (`zend.assertions = 0`), removing validation entirely.

**Solution:**

```php
// Replace assertions with explicit validation:
$operationId = $this->requestObject->getArgument('operation_id');

if (!is_string($operationId)) {
    throw new \InvalidArgumentException('Operation ID must be a string');
}

$this->validateOperationId($operationId);
```

Apply to all type assertions in all three files.

---

### 8. OperationListFunction Missing Pagination Validation

**Location:** OperationListFunction.php line 53

**Issue:**
Limit parameter accepted without validation:

```php
$limit = $this->requestObject->getArgument('limit', 50);
assert(is_int($limit)); // No bounds check
```

Users could request `limit: 999999`, causing performance issues.

**Solution:**

```php
$limit = $this->requestObject->getArgument('limit', 50);

if (!is_int($limit)) {
    throw new \InvalidArgumentException('Limit must be an integer');
}

if ($limit < 1 || $limit > 100) {
    throw new \InvalidArgumentException('Limit must be between 1 and 100');
}

// Clamp to safe maximum
$limit = min($limit, 50); // Enforce max 50 even if schema allows 100
```

---

## Architecture Recommendations

### 1. Extract Authorization to Middleware/Trait

```php
// Create authorization trait:
trait AuthorizesOperationAccess
{
    private function getRequesterId(): string
    {
        $requesterId = $this->requestObject->getAuthenticatedUserId();

        if ($requesterId === null) {
            throw new UnauthorizedException('Authentication required');
        }

        return $requesterId;
    }

    private function validateOwnership(OperationData $operation, string $requesterId): void
    {
        $ownerId = $operation->metadata['owner_id'] ?? null;

        if ($ownerId === null || $ownerId !== $requesterId) {
            throw OperationNotFoundException::create($operation->id);
        }
    }

    private function validateOperationId(string $operationId): void
    {
        if (!preg_match('/^op_[0-9a-f]{24}$/', $operationId)) {
            throw new \InvalidArgumentException("Invalid operation ID format: {$operationId}");
        }
    }
}

// Use in all three functions:
final class OperationCancelFunction extends AbstractFunction
{
    use AuthorizesOperationAccess;

    // ... rest of class
}
```

---

### 2. Add Domain Events

```php
// After cancelling:
event(new OperationCancelled($cancelledOperation, $requesterId));

// After status check:
event(new OperationStatusChecked($operation, $requesterId));

// After listing:
event(new OperationsListed($requesterId, count($operations)));
```

---

## Testing Recommendations

### Critical Security Tests

```php
test('cannot cancel operation owned by different user', function() {
    $operation = createOperation(['owner_id' => 'user-1']);
    $function = new OperationCancelFunction($repo, $logger);

    // Mock requester as different user
    $request = mockRequest(['authenticated_user_id' => 'user-2']);

    expect(fn() => $function->__invoke())
        ->toThrow(OperationNotFoundException::class);
});

test('cannot view status of operation owned by different user', function() {
    $operation = createOperation(['owner_id' => 'user-1']);
    $function = new OperationStatusFunction($repo, $logger);

    $request = mockRequest([
        'authenticated_user_id' => 'user-2',
        'arguments' => ['operation_id' => $operation->id],
    ]);

    expect(fn() => $function->__invoke())
        ->toThrow(OperationNotFoundException::class);
});

test('list only returns operations owned by requester', function() {
    createOperation(['owner_id' => 'user-1']);
    createOperation(['owner_id' => 'user-1']);
    createOperation(['owner_id' => 'user-2']); // Different owner

    $function = new OperationListFunction($repo, $logger);
    $request = mockRequest(['authenticated_user_id' => 'user-1']);

    $result = $function->__invoke();

    expect($result['operations'])->toHaveCount(2);
    expect($result['operations'])->each(
        fn($op) => expect($op['metadata']['owner_id'])->toBe('user-1')
    );
});

test('cancel prevents race condition with completion', function() {
    $operation = createOperation(['status' => OperationStatus::Processing]);

    // Simulate concurrent completion
    $repo->shouldReceive('compareAndSwap')
        ->once()
        ->andReturn(false); // CAS failed

    $function = new OperationCancelFunction($repo, $logger);

    expect(fn() => $function->__invoke())
        ->toThrow(RuntimeException::class, 'concurrent modification');
});

test('validates operation ID format', function() {
    $function = new OperationStatusFunction($repo, $logger);
    $request = mockRequest(['arguments' => ['operation_id' => 'invalid-format']]);

    expect(fn() => $function->__invoke())
        ->toThrow(InvalidArgumentException::class, 'Invalid operation ID format');
});

test('rate limits excessive status checks', function() {
    $operation = createOperation();
    $function = new OperationStatusFunction($repo, $logger);

    // Make 61 requests (exceeds limit of 60/min)
    foreach (range(1, 61) as $i) {
        if ($i === 61) {
            expect(fn() => $function->__invoke())
                ->toThrow(RateLimitException::class);
        } else {
            $function->__invoke();
        }
    }
});
```

---

## Summary

The async operation functions are structurally sound but have **critical security vulnerabilities** that must be fixed before production:

### Must Fix Immediately 🔴
1. **Add authorization** - Validate operation ownership in all functions
2. **Fix race conditions** - Use atomic operations for cancellation
3. **Prevent timing attacks** - Add constant-time delays for not-found cases

### Should Fix Soon 🟠
4. Add operation ID format validation
5. Implement comprehensive logging
6. Add rate limiting to prevent abuse

### Consider Fixing 🟡
7. Replace assertions with proper validation
8. Add pagination bounds validation

**Estimated Total Effort: 7-11 hours**

**CRITICAL:** These functions MUST NOT be deployed to production without addressing the authorization issues (#1). The current implementation allows any user to access and manipulate other users' operations.

---

**Files Referenced:**
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationCancelFunction.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationListFunction.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/Async/Functions/OperationStatusFunction.php`
- `/Users/brian/Developer/cline/forrst/src/Contracts/OperationRepositoryInterface.php` (needs updates)
- `/Users/brian/Developer/cline/forrst/src/Exceptions/UnauthorizedException.php` (may need to create)

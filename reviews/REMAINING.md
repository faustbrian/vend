# Remaining Architectural Work

Tasks that were explicitly skipped during code reviews as requiring larger architectural changes.

---

## 1. Stateful Extension Thread Safety

**Source:** Commit messages, IdempotencyExtension-review.md, Simple-Extensions-review.md

**Scope:** All stateful extensions (affects architectural pattern used across codebase)

> "Skipped Critical #3 (stateful extension thread safety) as it's an architectural concern affecting all extensions and would require a larger refactor. The current pattern is standard for PHP's request lifecycle."

**Affected Files:**
- `src/Extensions/IdempotencyExtension.php` - `$this->context` property
- `src/Extensions/DeadlineExtension.php` - `$requestStartTime`, `$specifiedTimeout` properties
- `src/Extensions/LocaleExtension.php` - `$resolvedLocale` property
- All extensions using instance state between event handlers

**Issue:** Mutable instance properties create race conditions in concurrent requests. State can leak between requests.

**Required Solution:** Store per-request state in event/request metadata instead of instance properties. Example pattern:
```php
// Instead of: $this->context = [...];
// Use: $event->request->meta['extension_name_context'] = [...];
```

**Estimated Effort:** 8-12 hours (affects multiple extensions)

---

## 2. Async Operation Authorization & Access Control

**Source:** Commit messages, AsyncExtension-individual-review.md, Async-Functions-review.md, OperationRepositoryInterface-review.md

> "CRITICAL items (authorization, race conditions, timing attacks) require architectural changes beyond scope of individual function improvements."

**Affected Files:**
- `src/Extensions/Async/AsyncExtension.php`
- `src/Extensions/Async/Functions/OperationCancelFunction.php`
- `src/Extensions/Async/Functions/OperationListFunction.php`
- `src/Extensions/Async/Functions/OperationStatusFunction.php`
- `src/Contracts/OperationRepositoryInterface.php`

**Issues:**
1. **Missing Authorization:** Any user can access/modify/cancel ANY operation
2. **No Ownership Validation:** Operations don't track owner; no access control
3. **Information Leakage:** Error messages reveal operation existence (enumeration attacks)
4. **Timing Attacks:** Silent failures create timing differences revealing valid IDs

**Required Solution:**
1. Add `owner_id` to operation metadata during creation
2. Add `?string $userId` parameter to all repository methods
3. Implement `validateOperationOwnership()` check in all operation methods
4. Use constant-time comparisons and random delays to prevent timing attacks
5. Return generic "not found or access denied" for both cases

**Estimated Effort:** 12-16 hours

---

## 3. Async Operation Race Conditions (Compare-and-Swap)

**Source:** Async-Functions-review.md, AsyncExtension-individual-review.md

**Affected Files:**
- `src/Extensions/Async/AsyncExtension.php` - `markProcessing()`, `complete()`, `fail()`, `updateProgress()`
- `src/Extensions/Async/Functions/OperationCancelFunction.php`
- `src/Contracts/OperationRepositoryInterface.php`

**Issue:** Check-then-act patterns without atomic operations cause race conditions:
```php
$operation = $this->operations->find($operationId);  // Read
// ... time passes, state could change ...
$this->operations->save($updated);  // Write (overwrites concurrent changes!)
```

**Required Solution:**
1. Add `operationVersion` field to `OperationData`
2. Add `compareAndSwap()` or `saveIfVersionMatches()` to repository interface
3. Implement optimistic locking with retry logic in all state transition methods
4. Handle `ConcurrentModificationException` appropriately

**Estimated Effort:** 8-10 hours

---

## 4. Webhook Service Implementation

**Source:** AsyncExtension-individual-review.md

> "Webhook Callback Security Not Addressed... While callback URLs are stored, there's no implementation showing: How callbacks are sent, Authentication/signing of callback payloads, Retry logic for failed callbacks, Protection against callback loops"

**Affected Files:**
- `src/Extensions/Async/AsyncExtension.php` (references callbacks but doesn't implement)
- New: `app/Services/AsyncWebhookService.php` (needs creation)

**Required Implementation:**
1. Create `AsyncWebhookService` with HMAC-signed payloads
2. Implement exponential backoff retry logic
3. Add callback URL validation (prevent SSRF)
4. Integrate with `complete()` and `fail()` methods
5. Add configuration in `config/forrst.php`

**Estimated Effort:** 6-8 hours

---

## 5. Async Operation TTL/Cleanup

**Source:** AsyncExtension-review.md, AsyncExtension-individual-review.md

> "No Operation Expiry/Cleanup Strategy... Operations accumulate indefinitely in the repository, leading to unbounded storage growth."

**Affected Files:**
- `src/Extensions/Async/AsyncExtension.php`
- `src/Contracts/OperationRepositoryInterface.php`
- New: `app/Console/Commands/CleanupExpiredOperations.php`

**Required Implementation:**
1. Add `expires_at` and `ttl_seconds` to operation metadata
2. Add `?int $ttl` parameter to `createAsyncOperation()`
3. Add `deleteExpiredBefore()` method to repository interface
4. Create cleanup artisan command
5. Schedule hourly cleanup in `app/Console/Kernel.php`

**Estimated Effort:** 4-6 hours

---

## 6. Async Operation Rate Limiting

**Source:** AsyncExtension-individual-review.md

> "No Rate Limiting on Operation Creation... Clients can create unlimited async operations, causing resource exhaustion."

**Affected Files:**
- `src/Extensions/Async/AsyncExtension.php`
- `src/Contracts/OperationRepositoryInterface.php` (add `countActiveByOwner()`)

**Required Implementation:**
1. Add rate limiting per user on operation creation (e.g., 100/minute)
2. Add concurrent operation limit per user (e.g., max 10 active)
3. Create `RateLimitException` and `QuotaExceededException`
4. Add configuration in `config/forrst.php`

**Estimated Effort:** 3-4 hours

---

## 7. Repository Interface Access Control

**Source:** OperationRepositoryInterface-review.md

> "The interface provides no mechanism for access control. Any code with repository access can find, modify, or delete any operation."

**Affected Files:**
- `src/Contracts/OperationRepositoryInterface.php`
- All implementations of this interface

**Required Changes:**
1. Add `?string $userId = null` parameter to all methods
2. Add `@throws \UnauthorizedAccessException` documentation
3. Document required database indexes
4. Add cursor format specification
5. Add concurrency handling documentation

**Estimated Effort:** 4-6 hours

---

## 8. Extension Event Handler Validation

**Source:** ExtensionInterface-review.md

> "The `getSubscribedEvents()` method returns method names as strings, which are later called dynamically. If an extension implementation doesn't properly validate these, it could lead to unintended method invocation."

**Affected Files:**
- Event dispatcher/subscriber (wherever extensions are registered)
- Possibly `src/Extensions/ExtensionEventSubscriber.php` or similar

**Required Implementation:**
1. Validate handler methods exist during extension registration
2. Validate methods are callable (check visibility)
3. Validate priority is an integer
4. Throw clear exceptions with extension/method/event details

**Estimated Effort:** 2-3 hours

---

## Summary by Priority

### Critical (Security)
- [ ] Async Operation Authorization & Access Control (~12-16 hours)
- [ ] Async Operation Race Conditions (~8-10 hours)

### High (Architectural)
- [ ] Stateful Extension Thread Safety (~8-12 hours)
- [ ] Repository Interface Access Control (~4-6 hours)

### Medium (Feature Completeness)
- [ ] Webhook Service Implementation (~6-8 hours)
- [ ] Async Operation TTL/Cleanup (~4-6 hours)
- [ ] Async Operation Rate Limiting (~3-4 hours)

### Low (Robustness)
- [ ] Extension Event Handler Validation (~2-3 hours)

**Total Estimated Effort:** 47-65 hours

---

## Items Marked as LOW Priority (Deferred)

These items were consistently deferred as "future enhancements":

1. **Interface Segregation** - Splitting large interfaces into smaller, focused ones
2. **Value Objects** - Replacing array structures with typed value objects
3. **Test Helpers** - Creating testing traits and base classes for extensions
4. **Extension Metadata** - Adding `getMetadata()` and `getDependencies()` methods
5. **Configuration Schema** - Adding JSON Schema support for extension configuration

These are documented in individual review files but are not blocking production use.

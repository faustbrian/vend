# Code Review: Simple Extensions (Deadline, Deprecation, DryRun, Locale)

**Files Reviewed:**
- `/Users/brian/Developer/cline/forrst/src/Extensions/DeadlineExtension.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/DeprecationExtension.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/DryRunExtension.php`
- `/Users/brian/Developer/cline/forrst/src/Extensions/LocaleExtension.php`

**Reviewed:** 2025-12-23
**Reviewer:** Senior Code Review Architect

---

## Executive Summary

These four extensions provide essential cross-cutting functionality: deadline management, deprecation warnings, dry-run validation, and localization. All are **well-architected with clean APIs** and excellent documentation. However, there are **timing issues**, **missing validation**, and **state management concerns** that need attention.

---

## Deadline Extension Issues

### 🟠 Stateful Extension Creates Thread Safety Issues

**Issue:** Mutable state (`$requestStartTime`, `$specifiedTimeout`) in extension instance.

**Location:** Lines 54-62

**Impact:**
- Race conditions in concurrent requests
- State leakage between requests
- Not thread-safe

**Solution:**

```php
// Store per-request state in event context instead:

public function onExecutingFunction(ExecutingFunction $event): void
{
    $startTime = CarbonImmutable::now();

    // Store in event metadata instead of instance property
    $event->request->meta['deadline_start'] = $startTime;

    // Store specified timeout in metadata
    if (isset($event->extension->options['timeout']) && is_array($event->extension->options['timeout'])) {
        $event->request->meta['deadline_specified'] = $event->extension->options['timeout'];
    }

    // ... rest of logic ...
}

public function onFunctionExecuted(FunctionExecuted $event): void
{
    // Retrieve from metadata
    $startTime = $event->request->meta['deadline_start'] ?? CarbonImmutable::now();
    $specifiedTimeout = $event->request->meta['deadline_specified'] ?? null;

    // ... rest of logic ...
}
```

### 🟡 Logic Error in Deadline Check

**Issue:** Line 120 checks `!$deadline->isPast()` - should be `$deadline->isPast()`.

**Location:** Line 120

**Impact:** Inverted logic - returns when deadline is valid, continues when expired.

**Solution:**

```php
// Line 119-122:
// Check if deadline has already passed
if ($deadline->isPast()) {  // Remove the negation
    // Set error response
    $event->setResponse(ResponseData::error(...));
    $event->stopPropagation();
}
```

### 🟡 No Maximum Deadline Enforcement

**Issue:** Clients can set arbitrarily far-future deadlines.

**Solution:**

```php
private function resolveDeadline(?array $options): ?CarbonImmutable
{
    // ... existing code ...

    if ($deadline !== null) {
        // Enforce maximum deadline (e.g., 1 hour from now)
        $maxDeadline = CarbonImmutable::now()->addHour();

        if ($deadline->isAfter($maxDeadline)) {
            throw new \InvalidArgumentException('Deadline cannot exceed 1 hour from now');
        }
    }

    return $deadline;
}
```

---

## Deprecation Extension Issues

### 🟡 No Warning Limit

**Issue:** Unlimited warnings can bloat responses.

**Location:** Line 274 (`getApplicableWarnings`)

**Impact:**
- Large response sizes
- Poor client performance
- Network overhead

**Solution:**

```php
// Add limit to warnings:

private const int MAX_WARNINGS = 10;

private function getApplicableWarnings(string $function, ?string $version, array $acknowledgedUrns): array
{
    $applicable = [];
    $count = 0;

    foreach ($this->warnings as $urn => $warning) {
        if ($count >= self::MAX_WARNINGS) {
            break;
        }

        // ... existing filtering logic ...

        $applicable[] = $warning;
        $count++;
    }

    return $applicable;
}
```

### 🟡 No Warning Expiration

**Issue:** Warnings registered at startup never expire or get removed.

**Location:** `registerWarning` method

**Impact:**
- Memory leak in long-running processes
- Old warnings for removed features persist
- No cleanup mechanism

**Solution:**

```php
// Add expiration check:

public function onFunctionExecuted(FunctionExecuted $event): void
{
    $this->pruneExpiredWarnings(); // Add this
    // ... rest of method ...
}

private function pruneExpiredWarnings(): void
{
    $now = CarbonImmutable::now();

    foreach ($this->warnings as $urn => $warning) {
        if (isset($warning['sunset_date'])) {
            $sunsetDate = CarbonImmutable::parse($warning['sunset_date']);

            // Remove warnings for features already removed (past sunset)
            if ($sunsetDate->isPast()) {
                unset($this->warnings[$urn]);
            }
        }
    }
}
```

###🟡 Acknowledged URNs Not Validated

**Issue:** `getAcknowledgedUrns` doesn't validate array type.

**Location:** Lines 256-260

**Impact:**
- Can cause type errors if client sends wrong type
- No error handling

**Solution:**

```php
private function getAcknowledgedUrns(?array $options): array
{
    $acknowledge = $options['acknowledge'] ?? [];

    if (!is_array($acknowledge)) {
        return [];
    }

    // Filter to only strings
    return array_filter($acknowledge, 'is_string');
}
```

---

## DryRun Extension Issues

### 🔵 No Actual Event Handling

**Issue:** Extension provides helper methods but doesn't hook into event lifecycle.

**Location:** Missing `getSubscribedEvents` implementation

**Impact:**
- Functions must manually check dry-run mode
- No automatic enforcement
- Easy to forget implementing dry-run

**Solution:**

This is actually intentional - DryRun is a "helper extension" that functions call explicitly. Document this clearly:

```php
/**
 * Dry-run extension handler.
 *
 * USAGE: This is a helper extension that does not automatically intercept
 * function execution. Functions must explicitly check for dry-run mode using
 * isEnabled() and implement dry-run logic themselves.
 *
 * Example:
 * ```php
 * public function __invoke(DryRunExtension $dryRun): ResponseData
 * {
 *     if ($dryRun->isEnabled($this->getExtensionOptions())) {
 *         return $dryRun->buildValidResponse($this->request, [
 *             $dryRun->buildWouldAffect('user', 123, 'update'),
 *         ]);
 *     }
 *     // ... actual mutation ...
 * }
 * ```
 */
```

### 🟡 Missing Schema Validation

**Issue:** No validation of diff, side_effects structures.

**Solution:**

```php
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
                'would_affect entries must have type and action'
            );
        }
    }

    // ... rest of method ...
}
```

---

## Locale Extension Issues

### 🟠 Stateful Extension Thread Safety

**Issue:** `$resolvedLocale` stored in extension instance.

**Location:** Lines 72-77

**Impact:**
- Same thread safety issues as DeadlineExtension
- Concurrent requests interfere
- Locale bleeds between requests

**Solution:**

Store resolved locale in request metadata or use per-request context object.

### 🟡 Language Negotiation Performance

**Issue:** `resolveLanguage` has nested loops that can be slow with many fallbacks.

**Location:** Lines 343-387

**Impact:**
- O(n*m) complexity with fallbacks
- Repeated string operations
- Can be optimized

**Solution:**

```php
// Cache resolution results:

private array $resolutionCache = [];

private function resolveLanguage(?string $requested, array $fallbacks): array
{
    $cacheKey = $requested . '|' . implode(',', $fallbacks);

    if (isset($this->resolutionCache[$cacheKey])) {
        return $this->resolutionCache[$cacheKey];
    }

    $result = $this->resolveLanguageUncached($requested, $fallbacks);

    $this->resolutionCache[$cacheKey] = $result;

    return $result;
}
```

### 🟡 No Locale Header Support

**Issue:** Only supports request options, not HTTP Accept-Language header.

**Location:** Missing feature

**Impact:**
- Not compatible with HTTP clients
- Requires custom request format
- Doesn't follow web standards

**Solution:**

```php
/**
 * Parse Accept-Language header.
 *
 * @param string $header Accept-Language header value
 * @return array<string, float> Map of language tags to quality values
 */
public function parseAcceptLanguage(string $header): array
{
    $languages = [];

    foreach (explode(',', $header) as $lang) {
        $parts = explode(';q=', trim($lang));
        $tag = $parts[0];
        $quality = isset($parts[1]) ? (float)$parts[1] : 1.0;

        $languages[$tag] = $quality;
    }

    // Sort by quality descending
    arsort($languages);

    return $languages;
}
```

---

## Testing Recommendations

**DeadlineExtension:**
- Concurrent requests don't interfere
- Deadline already passed returns error immediately
- Elapsed/remaining time calculations accurate
- Utilization percentage correct

**DeprecationExtension:**
- Warning filtering works correctly
- Acknowledged warnings suppressed
- Sunset dates handled properly
- Function vs version deprecation logic

**DryRunExtension:**
- Valid response structure correct
- Invalid response includes errors
- Helper methods produce correct formats

**LocaleExtension:**
- Language negotiation follows specification
- Fallback chain works correctly
- Timezone/currency validation accurate
- Thread safety with concurrent requests

---

## Summary

| Extension | Quality | Critical Issues | Major Issues | Minor Issues |
|-----------|---------|----------------|--------------|--------------|
| Deadline | 7/10 | 0 | 1 (thread safety) | 2 |
| Deprecation | 8/10 | 0 | 0 | 3 |
| DryRun | 9/10 | 0 | 0 | 2 |
| Locale | 8/10 | 0 | 1 (thread safety) | 2 |

**Overall:** Well-designed extensions with clean APIs. Main concern is thread safety in stateful extensions. Address state management issues before production use.

**Estimated Effort:** 6-8 hours to fix all issues

---

**Review completed:** 2025-12-23

# Code Review: ResourceInterface.php

## File Information
- **Path**: `/Users/brian/Developer/cline/forrst/src/Contracts/ResourceInterface.php`
- **Purpose**: Contract for resource transformers converting domain models to JSON representations
- **Type**: Interface

## SOLID Principles: ✅ EXCELLENT
Well-designed transformation contract with clear responsibilities.

## Critical Issues

### 🟡 Medium: Static Methods in @method Tags Not Validated

**Issue**: PHPDoc declares static methods but interface can't enforce them.

**Location**: Lines 28-32

**Current**:
```php
/**
 * @method static array<int, string> getFields()
 * @method static array<int, string> getFilters()
 * @method static array<int, string> getRelationships()
 * @method static array<int, string> getSorts()
 * @method static string             getModel()
 */
```

**Impact**: MEDIUM - No compile-time enforcement, implementations might not provide these

**Solution**: Add these to the interface as instance methods or document as convention:

```php
/**
 * CONVENTION: Implementations SHOULD provide static methods for resource metadata:
 * - getFields(): array - Available field definitions
 * - getFilters(): array - Available filter criteria  
 * - getRelationships(): array - Available relationships
 * - getSorts(): array - Available sort parameters
 * - getModel(): string - Fully qualified model class
 *
 * These cannot be enforced by interface but are required by the framework.
 */
interface ResourceInterface
{
    // ... existing methods
}
```

## Performance

### 🟡 Moderate: N+1 Query Risk

**Issue**: `getRelations()` could trigger N+1 if not properly eager-loaded.

**Solution**: Add documentation:

```php
/**
 * Get all loaded relationship data for this resource instance.
 *
 * PERFORMANCE: Only returns relationships that have been EAGER LOADED.
 * Never performs lazy loading. Implementations MUST check if a relation
 * is loaded before accessing it to prevent N+1 queries.
 *
 * @return array<string, mixed> Loaded relationship data
 */
public function getRelations(): array;
```

## Quality Rating: 🟢 EXCELLENT (8.5/10)

**Recommendation**: ✅ **APPROVED** - Add documentation about static methods and N+1 prevention.

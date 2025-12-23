# Code Review: DiscoveryServerData.php

**File**: `/Users/brian/Developer/cline/forrst/src/Discovery/DiscoveryServerData.php`
**Type**: Data Transfer Object (DTO)
**Extends**: `Spatie\LaravelData\Data`
**Review Date**: 2025-12-23
**Reviewer**: Senior Code Review Architect

---

## Executive Summary

DiscoveryServerData is a final, immutable DTO representing server endpoint configuration for Forrst service discovery documents. The class demonstrates strong adherence to immutability principles with readonly properties and comprehensive PHPDoc documentation. However, several architectural and validation concerns exist around URL template validation, variable consistency checking, and extension dependency validation. The class would benefit from runtime validation to ensure RFC 6570 URI template compliance and semantic validation between URL templates and their variable definitions.

**Overall Assessment**: 🟡 Minor Issues  
**SOLID Compliance**: 85%  
**Maintainability Score**: B+

---

## Detailed Analysis

### 1. SOLID Principles Evaluation

#### Single Responsibility Principle (SRP) ✅
**Status**: Compliant  
**Analysis**: The class has a single, well-defined responsibility: representing server endpoint configuration data. It doesn't perform validation, transformation, or business logic—purely data encapsulation.

#### Open/Closed Principle (OCP) ⚠️
**Status**: Partial Compliance  
**Issue**: While the `final` keyword prevents extension (by design), there's no mechanism to extend functionality through composition for specialized server types (e.g., OAuth-enabled servers, health-checked servers).

**Impact**: Future requirements for specialized server configurations will require parallel DTOs rather than composable extensions.

---

### 2. Code Quality Issues

#### 🟠 **MAJOR**: Missing RFC 6570 URI Template Validation
**Location**: Lines 54-61 (constructor)  
**Issue**: The `$url` parameter accepts any string without validating RFC 6570 URI template syntax. Invalid templates like `https://{environment.api.example.com` (missing closing brace) or `https://{env-name}.api` (invalid variable characters) will be accepted.

**Impact**:
- Runtime failures when clients attempt template substitution
- Silent corruption of discovery documents
- Poor developer experience during configuration

**Solution**: Add URL template validation in the constructor:

```php
<?php declare(strict_types=1);

namespace Cline\Forrst\Discovery;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class DiscoveryServerData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly ?string $summary = null,
        public readonly ?string $description = null,
        public readonly ?array $variables = null,
        public readonly ?array $extensions = null,
    ) {
        $this->validateUrlTemplate($url);
        $this->validateVariableConsistency($url, $variables);
    }

    /**
     * Validate RFC 6570 URI template syntax.
     *
     * @throws InvalidArgumentException
     */
    private function validateUrlTemplate(string $url): void
    {
        // Check for basic template syntax errors
        if (substr_count($url, '{') !== substr_count($url, '}')) {
            throw new InvalidArgumentException(
                "Invalid URI template: Mismatched braces in URL '{$url}'"
            );
        }

        // Extract and validate variable names
        preg_match_all('/\{([^}]+)\}/', $url, $matches);
        foreach ($matches[1] as $varName) {
            // RFC 6570: variable names must be [A-Za-z0-9_]+ (no hyphens, dots, etc.)
            if (!preg_match('/^[A-Za-z0-9_]+$/', $varName)) {
                throw new InvalidArgumentException(
                    "Invalid variable name '{$varName}' in URI template. "
                    ."Variable names must contain only letters, numbers, and underscores."
                );
            }
        }

        // Validate URL structure (basic sanity check)
        $testUrl = preg_replace('/\{[^}]+\}/', 'test', $url);
        if (!filter_var($testUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(
                "Invalid URL structure: '{$url}' is not a valid URL template"
            );
        }
    }

    /**
     * Ensure all URL template variables are defined in $variables array.
     *
     * @param array<string, ServerVariableData>|null $variables
     * @throws InvalidArgumentException
     */
    private function validateVariableConsistency(string $url, ?array $variables): void
    {
        preg_match_all('/\{([^}]+)\}/', $url, $matches);
        $urlVars = $matches[1];

        if (empty($urlVars)) {
            return; // No variables in template
        }

        if ($variables === null) {
            throw new InvalidArgumentException(
                'URL template contains variables '.json_encode($urlVars)
                .' but no variable definitions provided'
            );
        }

        $definedVars = array_keys($variables);
        $undefinedVars = array_diff($urlVars, $definedVars);

        if (!empty($undefinedVars)) {
            throw new InvalidArgumentException(
                'URL template references undefined variables: '.json_encode($undefinedVars)
            );
        }
    }
}
```

---

### 3. Testing Recommendations

#### Comprehensive Test Suite

```php
<?php

use Cline\Forrst\Discovery\DiscoveryServerData;
use Cline\Forrst\Discovery\ServerVariableData;
use Cline\Forrst\Discovery\ServerExtensionDeclarationData;

describe('DiscoveryServerData', function () {
    describe('Happy Path', function () {
        it('creates server with all fields', function () {
            $server = new DiscoveryServerData(
                name: 'Production',
                url: 'https://{env}.api.example.com/{version}',
                summary: 'Production API',
                description: 'Main production endpoint',
                variables: [
                    'env' => new ServerVariableData('env', ['prod'], 'prod'),
                    'version' => new ServerVariableData('version', ['v1', 'v2'], 'v1'),
                ],
                extensions: [
                    new ServerExtensionDeclarationData('urn:forrst:ext:async', '1.0.0'),
                ],
            );

            expect($server->name)->toBe('Production')
                ->and($server->url)->toContain('{env}')
                ->and($server->variables)->toHaveCount(2);
        });
    });

    describe('Sad Path - Validation Errors', function () {
        it('rejects mismatched braces in URL template', function () {
            expect(fn () => new DiscoveryServerData(
                name: 'Bad Server',
                url: 'https://{env.api.example.com',
            ))->toThrow(InvalidArgumentException::class, 'Mismatched braces');
        });

        it('rejects invalid variable names with hyphens', function () {
            expect(fn () => new DiscoveryServerData(
                name: 'Bad Server',
                url: 'https://{env-name}.api.example.com',
            ))->toThrow(InvalidArgumentException::class, 'Invalid variable name');
        });

        it('rejects URL with undefined variables', function () {
            expect(fn () => new DiscoveryServerData(
                name: 'Incomplete Server',
                url: 'https://{env}.api.example.com/{version}',
                variables: [
                    'env' => new ServerVariableData('env', ['prod'], 'prod'),
                    // Missing 'version' variable definition
                ],
            ))->toThrow(InvalidArgumentException::class, 'undefined variables');
        });
    });
});
```

---

## Summary of Recommendations

### High Priority (Should Fix)
1. **Add URL template validation** to catch RFC 6570 syntax errors
2. **Validate variable consistency** between URL templates and variable definitions
3. **Add array structure validation** for `$variables` and `$extensions`

### Medium Priority (Consider)
1. **Add named constructors** (`simple()`, `templated()`) for common patterns
2. **Document security considerations** for URL injection via template variables
3. **Add comprehensive unit tests** covering validation edge cases

---

## Conclusion

DiscoveryServerData is a well-designed, immutable DTO with excellent documentation and strong type safety. The primary improvement area is adding runtime validation to prevent invalid URI templates and inconsistent variable definitions from propagating through the system. These enhancements will significantly improve developer experience and system reliability with minimal performance impact.

**Recommended Action**: Implement high-priority validations before deploying to production.

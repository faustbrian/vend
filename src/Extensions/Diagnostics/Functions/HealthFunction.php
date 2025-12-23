<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Extensions\Diagnostics\Functions;

use Carbon\CarbonImmutable;
use Cline\Forrst\Attributes\Descriptor;
use Cline\Forrst\Contracts\HealthCheckerInterface;
use Cline\Forrst\Exceptions\TooManyRequestsException;
use Cline\Forrst\Exceptions\UnauthorizedException;
use Cline\Forrst\Extensions\Diagnostics\Descriptors\HealthDescriptor;
use Cline\Forrst\Functions\AbstractFunction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Psr\Log\LoggerInterface;

/**
 * Comprehensive health check system function.
 *
 * Implements forrst.health for component-level health checks by aggregating
 * health status from all registered health checker instances.
 *
 * Special component values:
 * - "self": Returns immediate healthy response without checking components (lightweight ping)
 * - null: Checks all registered components
 * - specific component name: Checks only that component
 *
 * @author Brian Faust <brian@cline.sh>
 *
 * @see https://docs.cline.sh/forrst/system-functions
 */
#[Descriptor(HealthDescriptor::class)]
final class HealthFunction extends AbstractFunction
{
    private const int HEALTH_CACHE_TTL = 10; // seconds
    private const int RATE_LIMIT_PER_MINUTE = 60;
    private const float HEALTH_CHECK_TIMEOUT = 5.0; // seconds

    /**
     * Create a new health function instance.
     *
     * @param array<int, HealthCheckerInterface> $checkers               Array of registered health checker instances
     * @param bool                               $requireAuthForDetails Whether to require authentication for detailed health info
     * @param null|LoggerInterface               $logger                Logger instance for warnings and errors
     */
    public function __construct(
        private readonly array $checkers = [],
        private readonly bool $requireAuthForDetails = true,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Execute the health check function.
     *
     * @throws TooManyRequestsException If rate limit is exceeded
     * @throws UnauthorizedException    If detailed information is requested without authentication
     *
     * @return array<string, mixed> Health check response
     */
    public function __invoke(): array
    {
        // Rate limit health checks
        $clientIp = $this->requestObject->getContext('client_ip', 'unknown');
        $rateLimitKey = "health_check:{$clientIp}";

        if (!RateLimiter::attempt($rateLimitKey, self::RATE_LIMIT_PER_MINUTE, fn (): bool => true, 60)) {
            $this->logger?->warning('Health check rate limit exceeded', [
                'ip' => $clientIp,
                'limit' => self::RATE_LIMIT_PER_MINUTE,
            ]);

            throw TooManyRequestsException::createWithDetails(
                limit: self::RATE_LIMIT_PER_MINUTE,
                used: self::RATE_LIMIT_PER_MINUTE,
                windowValue: 1,
                windowUnit: 'minute',
                retryAfterValue: 60,
                retryAfterUnit: 'second',
                detail: 'Too many health check requests. Please try again later.',
            );
        }

        $component = $this->requestObject->getArgument('component');
        $includeDetails = $this->requestObject->getArgument('include_details', true);

        // Require authentication for detailed health info
        if ($includeDetails && $this->requireAuthForDetails && !$this->isAuthenticated()) {
            throw UnauthorizedException::create('Authentication required for detailed health information');
        }

        // Limit component details for unauthenticated requests
        if (!$this->isAuthenticated()) {
            $includeDetails = false;
        }

        // Handle 'self' component check early (lightweight ping)
        if ($component === 'self') {
            return [
                'status' => 'healthy',
                'timestamp' => CarbonImmutable::now()->toIso8601String(),
            ];
        }

        // Use cache for anonymous health checks
        if (!$this->isAuthenticated() && $component === null) {
            $cacheKey = 'health_check:public';

            return Cache::remember($cacheKey, self::HEALTH_CACHE_TTL, function () use ($includeDetails): array {
                return $this->performHealthChecks($includeDetails, null);
            });
        }

        // Always execute fresh checks for authenticated users or specific components
        return $this->performHealthChecks($includeDetails, $component);
    }

    /**
     * Perform health checks with timeout protection and error handling.
     *
     * @param bool        $includeDetails Whether to include detailed health info
     * @param null|string $component      Specific component to check or null for all
     *
     * @return array<string, mixed> Health check response
     */
    private function performHealthChecks(bool $includeDetails, ?string $component): array
    {
        $components = [];
        $worstStatus = 'healthy';
        $startTime = microtime(true);

        foreach ($this->checkers as $checker) {
            // Check timeout
            if (microtime(true) - $startTime > self::HEALTH_CHECK_TIMEOUT) {
                $this->logger?->warning('Health check timeout reached', [
                    'elapsed' => microtime(true) - $startTime,
                    'timeout' => self::HEALTH_CHECK_TIMEOUT,
                ]);

                break;
            }

            if ($component !== null && $checker->getName() !== $component) {
                continue;
            }

            try {
                $result = $checker->check();

                // Sanitize output based on authentication
                $components[$checker->getName()] = $this->sanitizeHealthResult(
                    $result,
                    $includeDetails,
                    $this->isAuthenticated(),
                );

                $worstStatus = $this->worstStatus($worstStatus, $result['status']);
            } catch (\Throwable $e) {
                $this->logger?->error('Health checker failed', [
                    'checker' => $checker->getName(),
                    'error' => $e->getMessage(),
                ]);

                $components[$checker->getName()] = [
                    'status' => 'unhealthy',
                    'error' => 'Health check failed',
                ];

                $worstStatus = $this->worstStatus($worstStatus, 'unhealthy');
            }
        }

        $response = [
            'status' => $worstStatus,
            'timestamp' => CarbonImmutable::now()->toIso8601String(),
        ];

        if ($components !== []) {
            $response['components'] = $components;
        }

        return $response;
    }

    /**
     * Check if request is authenticated.
     */
    private function isAuthenticated(): bool
    {
        // Check for user_id in context which indicates authentication
        return $this->requestObject->getContext('user_id') !== null;
    }

    /**
     * Sanitize health result based on authentication level.
     *
     * @param array<string, mixed> $result          Raw health check result
     * @param bool                 $includeDetails  Whether to include details
     * @param bool                 $isAuthenticated Whether request is authenticated
     *
     * @return array<string, mixed> Sanitized health result
     */
    private function sanitizeHealthResult(array $result, bool $includeDetails, bool $isAuthenticated): array
    {
        if (!$includeDetails) {
            return ['status' => $result['status']];
        }

        if (!$isAuthenticated) {
            // Only return status for unauthenticated users
            return ['status' => $result['status']];
        }

        // Remove sensitive fields even for authenticated users
        $sanitized = $result;
        unset(
            $sanitized['connection_string'],
            $sanitized['password'],
            $sanitized['secret'],
            $sanitized['api_key'],
            $sanitized['token'],
            $sanitized['credentials'],
        );

        return $sanitized;
    }

    /**
     * Compare and return the worst status between current and new.
     */
    private function worstStatus(string $current, string $new): string
    {
        $order = ['healthy' => 0, 'degraded' => 1, 'unhealthy' => 2];

        return ($order[$new] ?? 0) > ($order[$current] ?? 0) ? $new : $current;
    }
}

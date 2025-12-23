<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Forrst\Data;

/**
 * Health status value object.
 *
 * Provides type-safe representation of health check results with validation
 * and convenience methods for status inspection. Ensures consistent health
 * status structure across all health checkers.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final readonly class HealthStatus
{
    /**
     * Create a new health status instance.
     *
     * @param 'healthy'|'degraded'|'unhealthy' $status Health status value
     * @param array{value: int, unit: string}|null $latency Optional latency metrics
     * @param string|null $message Optional diagnostic message
     * @param string|null $lastCheck Optional last check timestamp
     *
     * @throws \InvalidArgumentException If status or latency values are invalid
     */
    public function __construct(
        public string $status,
        public ?array $latency = null,
        public ?string $message = null,
        public ?string $lastCheck = null,
    ) {
        if (!in_array($status, ['healthy', 'degraded', 'unhealthy'], true)) {
            throw new \InvalidArgumentException(
                "Invalid status '{$status}'. Must be: healthy, degraded, or unhealthy"
            );
        }

        if ($latency !== null) {
            if (!isset($latency['value'], $latency['unit'])) {
                throw new \InvalidArgumentException(
                    "Latency must have 'value' and 'unit' keys"
                );
            }
            if (!is_int($latency['value']) || $latency['value'] < 0) {
                throw new \InvalidArgumentException(
                    'Latency value must be a non-negative integer'
                );
            }
            if (!in_array($latency['unit'], ['ms', 'us', 's'], true)) {
                throw new \InvalidArgumentException(
                    "Invalid latency unit '{$latency['unit']}'. Must be: ms, us, or s"
                );
            }
        }
    }

    /**
     * Convert health status to array representation.
     *
     * @return array{status: string, latency?: array{value: int, unit: string}, message?: string, last_check?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'latency' => $this->latency,
            'message' => $this->message,
            'last_check' => $this->lastCheck,
        ], fn($value) => $value !== null);
    }

    /**
     * Check if component is healthy.
     */
    public function isHealthy(): bool
    {
        return $this->status === 'healthy';
    }

    /**
     * Check if component is degraded.
     */
    public function isDegraded(): bool
    {
        return $this->status === 'degraded';
    }

    /**
     * Check if component is unhealthy.
     */
    public function isUnhealthy(): bool
    {
        return $this->status === 'unhealthy';
    }
}

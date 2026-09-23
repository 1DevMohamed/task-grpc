<?php

namespace App\Integration\Metrics;

use Illuminate\Support\Facades\Log;

/**
 * Simple metrics collector using structured log entries.
 *
 * These log-based metrics are compatible with log aggregation and
 * monitoring systems (ELK, CloudWatch Logs, Datadog, etc.).
 *
 * In production, this could be replaced with a StatsD/Prometheus
 * implementation without changing the calling code.
 */
class MetricsCollector
{
    /**
     * Increment a counter metric.
     */
    public function increment(string $metric, int $value = 1, array $tags = []): void
    {
        Log::info('integration.metric', [
            'metric' => $metric,
            'value'  => $value,
            'type'   => 'counter',
            ...$tags,
        ]);
    }

    /**
     * Record a duration metric.
     */
    public function duration(string $metric, float $durationMs, array $tags = []): void
    {
        Log::info('integration.metric', [
            'metric'      => $metric,
            'duration_ms' => round($durationMs, 2),
            'type'        => 'duration',
            ...$tags,
        ]);
    }

    // ── Convenience methods ────────────────────────────────

    public function messageReceived(string $eventType): void
    {
        $this->increment('messages_received', tags: ['event_type' => $eventType]);
    }

    public function messageProcessed(string $eventType, float $durationMs): void
    {
        $this->increment('messages_processed', tags: ['event_type' => $eventType]);
        $this->duration('processing_duration', $durationMs, ['event_type' => $eventType]);
    }

    public function messageFailed(string $eventType, string $errorCategory): void
    {
        $this->increment('messages_failed', tags: [
            'event_type'     => $eventType,
            'error_category' => $errorCategory,
        ]);
    }

    public function messageRetried(string $eventType): void
    {
        $this->increment('messages_retried', tags: ['event_type' => $eventType]);
    }

    public function messageDlq(string $eventType): void
    {
        $this->increment('messages_dlq', tags: ['event_type' => $eventType]);
    }

    public function duplicateEvent(string $eventType): void
    {
        $this->increment('duplicate_events', tags: ['event_type' => $eventType]);
    }

    public function unknownEventType(string $eventType): void
    {
        $this->increment('unknown_event_types', tags: ['event_type' => $eventType]);
    }

    public function rpcSuccess(string $service, string $method, float $durationMs): void
    {
        $this->increment('rpc_success', tags: ['service' => $service, 'method' => $method]);
        $this->duration('rpc_duration', $durationMs, ['service' => $service, 'method' => $method]);
    }

    public function rpcFailure(string $service, string $method): void
    {
        $this->increment('rpc_failure', tags: ['service' => $service, 'method' => $method]);
    }

    public function rpcTimeout(string $service, string $method): void
    {
        $this->increment('rpc_timeout', tags: ['service' => $service, 'method' => $method]);
    }
}

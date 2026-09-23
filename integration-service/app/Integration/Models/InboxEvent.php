<?php

namespace App\Integration\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents an event tracked in the integration inbox.
 *
 * The UNIQUE constraint on event_id is the primary idempotency mechanism.
 * Combined with the status state machine, this prevents duplicate
 * business operations even under concurrent worker processing.
 *
 * @property int         $id
 * @property string      $event_id
 * @property string      $event_type
 * @property string      $aggregate_type
 * @property string      $aggregate_id
 * @property string      $correlation_id
 * @property string|null $merchant_id
 * @property string      $status
 * @property int         $attempts
 * @property array       $payload
 * @property string|null $error_message
 * @property string|null $error_category
 * @property string|null $received_at
 * @property string|null $processing_started_at
 * @property string|null $processed_at
 * @property string|null $failed_at
 */
#[Fillable([
    'event_id', 'event_type', 'aggregate_type', 'aggregate_id',
    'correlation_id', 'merchant_id', 'status', 'attempts',
    'payload', 'error_message', 'error_category',
    'received_at', 'processing_started_at', 'processed_at', 'failed_at',
])]
class InboxEvent extends Model
{
    protected $table = 'integration_inbox';

    // Status constants
    public const string STATUS_RECEIVED   = 'RECEIVED';
    public const string STATUS_PROCESSING = 'PROCESSING';
    public const string STATUS_PROCESSED  = 'PROCESSED';
    public const string STATUS_FAILED     = 'FAILED';

    protected function casts(): array
    {
        return [
            'payload'                => 'array',
            'attempts'               => 'integer',
            'received_at'            => 'datetime',
            'processing_started_at'  => 'datetime',
            'processed_at'           => 'datetime',
            'failed_at'              => 'datetime',
        ];
    }

    // ── Scopes ─────────────────────────────────────────────

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeStaleProcessing(Builder $query, int $thresholdSeconds = 120): Builder
    {
        return $query
            ->where('status', self::STATUS_PROCESSING)
            ->where('processing_started_at', '<', now()->subSeconds($thresholdSeconds));
    }

    // ── State checks ───────────────────────────────────────

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Whether this record appears to be stale (worker crashed while processing).
     */
    public function isStaleProcessing(int $thresholdSeconds = 120): bool
    {
        if ($this->status !== self::STATUS_PROCESSING) {
            return false;
        }

        if ($this->processing_started_at === null) {
            return true;
        }

        return $this->processing_started_at->diffInSeconds(now()) > $thresholdSeconds;
    }
}

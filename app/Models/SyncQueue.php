<?php

namespace App\Models;

use App\Enums\SyncDirection;
use App\Enums\SyncStatus;
use Database\Factories\SyncQueueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncQueue extends Model
{
    /** @use HasFactory<SyncQueueFactory> */
    use HasFactory;

    protected $table = 'sync_queue';

    protected $fillable = [
        'direction',
        'entity_type',
        'entity_id',
        'payload',
        'priority',
        'status',
        'attempts',
        'max_attempts',
        'last_error',
        'scheduled_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => SyncDirection::class,
            'payload' => 'array',
            'priority' => 'integer',
            'status' => SyncStatus::class,
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Check if this item can be retried.
     */
    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    /**
     * Mark this item as completed.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => SyncStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    /**
     * Record a failure with backoff delay.
     */
    public function recordFailure(string $error): void
    {
        $this->increment('attempts');
        $this->update([
            'last_error' => $error,
            'status' => $this->canRetry() ? SyncStatus::Pending : SyncStatus::Failed,
            'scheduled_at' => now()->addSeconds($this->backoffDelay()),
        ]);
    }

    /**
     * Calculate the backoff delay based on attempt count.
     */
    public function backoffDelay(): int
    {
        return match (true) {
            $this->attempts <= 1 => 30,
            $this->attempts <= 2 => 60,
            $this->attempts <= 3 => 120,
            $this->attempts <= 4 => 300,
            default => 600,
        };
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter items ready to be processed.
     */
    public function scopeReady($query)
    {
        return $query->where('status', SyncStatus::Pending)
            ->where('scheduled_at', '<=', now())
            ->orderByDesc('priority');
    }

    /**
     * Scope to filter by direction.
     */
    public function scopeDirection($query, SyncDirection $direction)
    {
        return $query->where('direction', $direction);
    }
}

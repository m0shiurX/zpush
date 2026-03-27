<?php

namespace App\Models;

use App\Enums\SyncDirection;
use App\Enums\SyncStatus;
use Carbon\Carbon;
use Database\Factories\SyncLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    /** @use HasFactory<SyncLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'cloud_server_id',
        'device_id',
        'direction',
        'entity_type',
        'records_affected',
        'status',
        'error_message',
        'duration_ms',
        'started_at',
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
            'status' => SyncStatus::class,
            'records_affected' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the cloud server for this sync log.
     */
    public function cloudServer(): BelongsTo
    {
        return $this->belongsTo(CloudServer::class);
    }

    /**
     * Get the device for this sync log.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceConfig::class, 'device_id');
    }

    // ==========================================
    // Factory Methods
    // ==========================================

    /**
     * Create a sync log entry for a completed operation.
     *
     * @param  array{cloud_server_id?: int, device_id?: int, direction: SyncDirection, entity_type: string, records_affected: int, started_at: Carbon}  $attributes
     */
    public static function logSuccess(array $attributes): static
    {
        return static::create([
            ...$attributes,
            'status' => SyncStatus::Completed,
            'completed_at' => now(),
            'duration_ms' => $attributes['started_at']->diffInMilliseconds(now()),
        ]);
    }

    /**
     * Create a sync log entry for a failed operation.
     *
     * @param  array{cloud_server_id?: int, device_id?: int, direction: SyncDirection, entity_type: string, records_affected: int, started_at: Carbon, error_message: string}  $attributes
     */
    public static function logFailure(array $attributes): static
    {
        return static::create([
            ...$attributes,
            'status' => SyncStatus::Failed,
            'completed_at' => now(),
            'duration_ms' => $attributes['started_at']->diffInMilliseconds(now()),
        ]);
    }
}

<?php

namespace App\Models;

use App\Enums\PunchType;
use Database\Factories\AttendanceLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    /** @use HasFactory<AttendanceLogFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'device_uid',
        'device_id',
        'timestamp',
        'punch_type',
        'cloud_synced',
        'cloud_synced_at',
        'cloud_sync_attempts',
        'last_sync_error',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'device_uid' => 'integer',
            'timestamp' => 'datetime',
            'punch_type' => PunchType::class,
            'cloud_synced' => 'boolean',
            'cloud_synced_at' => 'datetime',
            'cloud_sync_attempts' => 'integer',
        ];
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the employee for this attendance log.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Get the device that recorded this attendance.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceConfig::class, 'device_id');
    }

    // ==========================================
    // Sync Methods
    // ==========================================

    /**
     * Mark this log as synced to the cloud.
     */
    public function markAsSynced(): void
    {
        $this->update([
            'cloud_synced' => true,
            'cloud_synced_at' => now(),
            'last_sync_error' => null,
        ]);
    }

    /**
     * Record a sync failure.
     */
    public function recordSyncFailure(string $error): void
    {
        $this->increment('cloud_sync_attempts');
        $this->update(['last_sync_error' => $error]);
    }

    /**
     * Build the payload for cloud sync.
     *
     * @return array{employee_code: string|null, device_uid: int, timestamp: string, punch_type: int, device_name: string|null}
     */
    public function toSyncPayload(): array
    {
        return [
            'employee_code' => $this->employee?->employee_code,
            'device_uid' => $this->device_uid,
            'timestamp' => $this->timestamp->toISOString(),
            'punch_type' => $this->getRawOriginal('punch_type'),
            'device_name' => $this->device?->name,
        ];
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter unsynced attendance logs.
     */
    public function scopeUnsynced($query)
    {
        return $query->where('cloud_synced', false);
    }

    /**
     * Scope to filter today's attendance logs.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('timestamp', today());
    }
}

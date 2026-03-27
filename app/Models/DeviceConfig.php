<?php

namespace App\Models;

use Database\Factories\DeviceConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class DeviceConfig extends Model
{
    /** @use HasFactory<DeviceConfigFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'ip_address',
        'port',
        'protocol',
        'poll_method',
        'is_active',
        'last_connected_at',
        'last_poll_at',
        'connection_failures',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'protocol' => 'string',
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
            'last_poll_at' => 'datetime',
            'connection_failures' => 'integer',
        ];
    }

    /**
     * Check if the device uses TCP protocol.
     */
    public function isTcp(): bool
    {
        return ($this->protocol ?? 'tcp') === 'tcp';
    }

    /**
     * Check if the device uses real-time listener mode.
     */
    public function isRealtime(): bool
    {
        return ($this->poll_method ?? 'realtime') === 'realtime';
    }

    /**
     * Check if the device uses bulk polling mode.
     */
    public function isBulk(): bool
    {
        return $this->poll_method === 'bulk';
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the attendance logs from this device.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class, 'device_id');
    }

    /**
     * Get the sync logs for this device.
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'device_id');
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Check if the listener command is actively connected to this device.
     */
    public function isListening(): bool
    {
        return Cache::has("device:{$this->id}:listening");
    }

    /**
     * Check if the device is currently connected (listener active or polled recently with no failures).
     */
    public function isConnected(): bool
    {
        if ($this->isListening()) {
            return true;
        }

        return $this->connection_failures === 0
            && $this->last_connected_at?->gt(now()->subMinutes(2));
    }

    /**
     * Record a successful connection.
     */
    public function recordSuccess(): void
    {
        $this->update([
            'last_connected_at' => now(),
            'connection_failures' => 0,
        ]);
    }

    /**
     * Record a connection failure.
     */
    public function recordFailure(): void
    {
        $this->increment('connection_failures');
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter only active devices.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

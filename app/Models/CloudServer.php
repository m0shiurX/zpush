<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloudServer extends Model
{
    /** @use HasFactory<\Database\Factories\CloudServerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'api_base_url',
        'api_key',
        'is_active',
        'is_connected',
        'last_successful_sync',
        'last_failed_sync',
        'sync_failure_count',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'is_connected' => 'boolean',
            'last_successful_sync' => 'datetime',
            'last_failed_sync' => 'datetime',
            'sync_failure_count' => 'integer',
        ];
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the sync logs for this cloud server.
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class);
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Record a successful sync.
     */
    public function recordSyncSuccess(): void
    {
        $this->update([
            'is_connected' => true,
            'last_successful_sync' => now(),
            'sync_failure_count' => 0,
        ]);
    }

    /**
     * Record a sync failure.
     */
    public function recordSyncFailure(): void
    {
        $this->increment('sync_failure_count');
        $this->update([
            'last_failed_sync' => now(),
            'is_connected' => false,
        ]);
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter only active cloud servers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter connected cloud servers.
     */
    public function scopeConnected($query)
    {
        return $query->where('is_connected', true);
    }
}

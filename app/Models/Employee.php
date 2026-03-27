<?php

namespace App\Models;

use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'cloud_id',
        'device_uid',
        'name',
        'employee_code',
        'card_number',
        'department',
        'is_active',
        'cloud_synced_at',
        'device_synced_at',
        'sync_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cloud_id' => 'integer',
            'device_uid' => 'integer',
            'is_active' => 'boolean',
            'cloud_synced_at' => 'datetime',
            'device_synced_at' => 'datetime',
        ];
    }

    /**
     * Auto-compute sync_hash on save for change detection.
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $employee->sync_hash = md5(json_encode([
                $employee->name,
                $employee->department,
                $employee->employee_code,
                $employee->is_active,
            ]));
        });
    }

    // ==========================================
    // Relationships
    // ==========================================

    /**
     * Get the attendance logs for this employee.
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Check if the employee has been synced to the cloud.
     */
    public function isCloudSynced(): bool
    {
        return $this->cloud_synced_at !== null;
    }

    /**
     * Check if the employee has been synced to a device.
     */
    public function isDeviceSynced(): bool
    {
        return $this->device_synced_at !== null;
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter only active employees.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter employees not yet synced to the cloud.
     */
    public function scopeUnsyncedToCloud($query)
    {
        return $query->whereNull('cloud_synced_at');
    }
}

<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'status' => UserStatus::class,
        ];
    }

    // ==========================================
    // Status Methods
    // ==========================================

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Check if the user is inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === UserStatus::Inactive;
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * Scope to filter only active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', UserStatus::Active);
    }

    /**
     * Scope to filter only inactive users.
     */
    public function scopeInactive($query)
    {
        return $query->where('status', UserStatus::Inactive);
    }

    // ==========================================
    // Role & Permission Helpers
    // ==========================================

    /**
     * Check if user has only Employee role (no admin privileges).
     */
    public function isEmployeeOnly(): bool
    {
        return $this->hasRole('Employee') && ! $this->hasAnyRole(['Super Admin', 'Admin']);
    }

    /**
     * Check if user is admin or super admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasAnyRole(['Super Admin', 'Admin']);
    }
}

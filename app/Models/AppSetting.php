<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    // ==========================================
    // Static Accessors
    // ==========================================

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $decoded = json_decode($setting->value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
    }

    /**
     * Set a setting value by key.
     */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_string($value) ? $value : json_encode($value)],
        );
    }

    /**
     * Check if a boolean setting is true.
     */
    public static function isTrue(string $key): bool
    {
        return filter_var(static::get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Get all settings as a key-value array.
     *
     * @return array<string, mixed>
     */
    public static function allSettings(): array
    {
        return static::all()
            ->mapWithKeys(function (AppSetting $setting): array {
                $decoded = json_decode($setting->value, true);

                return [$setting->key => json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value];
            })
            ->toArray();
    }
}

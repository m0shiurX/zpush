<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions from config registry
        $config = config('permissions', []);

        foreach ($config as $feature => $definition) {
            $group = $definition['group'] ?? null;

            foreach ($definition['abilities'] ?? [] as $ability) {
                Permission::firstOrCreate(
                    ['name' => "{$feature}_{$ability}", 'guard_name' => 'web'],
                    ['group' => $group],
                );
            }

            foreach ($definition['custom'] ?? [] as $custom) {
                Permission::firstOrCreate(
                    ['name' => "{$feature}_{$custom}", 'guard_name' => 'web'],
                    ['group' => $group],
                );
            }
        }

        // Create Admin role (the only role needed for this app)
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::where('name', 'Admin')->where('guard_name', 'web')->delete();

        $config = config('permissions', []);

        foreach ($config as $feature => $definition) {
            foreach ($definition['abilities'] ?? [] as $ability) {
                Permission::where('name', "{$feature}_{$ability}")->where('guard_name', 'web')->delete();
            }

            foreach ($definition['custom'] ?? [] as $custom) {
                Permission::where('name', "{$feature}_{$custom}")->where('guard_name', 'web')->delete();
            }
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};

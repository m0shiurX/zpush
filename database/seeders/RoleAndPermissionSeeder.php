<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->syncPermissions();

        // Admin is the only role — gets full access via Gate::before
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('✓ Roles and permissions seeded successfully!');
    }

    /**
     * Sync all permissions from config — creates new ones and removes stale ones.
     */
    protected function syncPermissions(): void
    {
        $config = config('permissions', []);
        $configPermissions = [];

        foreach ($config as $feature => $definition) {
            $group = $definition['group'] ?? null;

            foreach ($definition['abilities'] ?? [] as $ability) {
                $configPermissions["{$feature}_{$ability}"] = $group;
            }

            foreach ($definition['custom'] ?? [] as $custom) {
                $configPermissions["{$feature}_{$custom}"] = $group;
            }
        }

        $created = 0;
        $updated = 0;

        foreach ($configPermissions as $name => $group) {
            $permission = Permission::where('name', $name)->where('guard_name', 'web')->first();

            if (! $permission) {
                Permission::create(['name' => $name, 'guard_name' => 'web', 'group' => $group]);
                $created++;
            } elseif ($permission->group !== $group) {
                $permission->update(['group' => $group]);
                $updated++;
            }
        }

        $deleted = Permission::where('guard_name', 'web')
            ->whereNotIn('name', array_keys($configPermissions))
            ->delete();

        $this->command->info("  Permissions: {$created} created, {$updated} updated, {$deleted} removed");
    }
}

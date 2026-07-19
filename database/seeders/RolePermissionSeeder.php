<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the role/permission catalog from SRS v1.1 §3.1's permission matrix.
 *
 * Permissions are global (Spatie doesn't team-scope the `permissions` table).
 * Roles ARE team-scoped (one "Cashier" role row per tenant), so seedForTeam()
 * must run once per tenant.
 *
 * Super Admin isn't in this catalog: Spatie's teams pivot tables require a
 * non-null team id, which conflicts with Super Admin having tenant_id = null.
 * It's instead a platform-level flag on User, bypassing Gate checks entirely
 * — see App\Providers\AppServiceProvider::boot().
 */
class RolePermissionSeeder extends Seeder
{
    public const GUARD = 'web';

    public const PERMISSIONS = [
        'tenant-settings.manage',
        'users.manage',
        'stock.view',
        'stock.manage',
        'sales.create',
        'sales.view',
        'sales.void.approve',
        'suppliers.view',
        'suppliers.manage',
        'reports.view',
        'reports.view-all-tenants',
    ];

    /**
     * Row/branch-level scoping ("own branch only", "own transactions only")
     * from §3.1 is enforced in query scopes and policies, not permission
     * names — these grant the action, not its row-level boundary.
     */
    public const ROLE_PERMISSIONS = [
        'Business Owner' => [
            'tenant-settings.manage', 'users.manage', 'stock.view', 'stock.manage',
            'sales.create', 'sales.view', 'sales.void.approve',
            'suppliers.view', 'suppliers.manage', 'reports.view',
        ],
        'Branch Manager' => [
            'users.manage', 'stock.view', 'stock.manage',
            'sales.create', 'sales.view', 'sales.void.approve',
            'suppliers.view', 'reports.view',
        ],
        'Cashier' => [
            'stock.view', 'sales.create', 'sales.view',
        ],
        'Store Keeper' => [
            'stock.view', 'stock.manage', 'suppliers.manage',
        ],
        'Auditor' => [
            'stock.view', 'sales.view', 'suppliers.view', 'reports.view',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, self::GUARD);
        }
    }

    public function seedForTeam(?int $tenantId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            Role::findOrCreate($roleName, self::GUARD)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}

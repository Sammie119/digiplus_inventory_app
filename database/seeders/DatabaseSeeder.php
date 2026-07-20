<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        // Platform-level Super Admin — flag-based, not a tenant-scoped Spatie
        // role (see App\Providers\AppServiceProvider::boot()).
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@digiplus.test',
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => true,
        ]);

        // A demo tenant + branch + owner to log in and explore the app with.
        $tenant = Tenant::create([
            'name' => 'Demo Retail Co',
            'subdomain' => 'demo',
            'currency' => 'GHS',
            'status' => 'active',
        ]);

        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_main_branch' => true,
        ]);

        // seedForTeam() also sets the current Spatie team id to $tenant->id,
        // so the assignRole() call below lands in the right team scope.
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $owner = User::factory()->create([
            'name' => 'Demo Owner',
            'email' => 'owner@demo.test',
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $owner->assignRole('Business Owner');

        $branchManager = User::factory()->create([
            'name' => 'Demo Branch Manager',
            'email' => 'manager@demo.test',
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $branchManager->assignRole('Branch Manager');

        $cashier = User::factory()->create([
            'name' => 'Demo Cashier',
            'email' => 'cashier@demo.test',
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $cashier->assignRole('Cashier');
    }
}

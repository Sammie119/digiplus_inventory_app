<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
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

        $downtownBranch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Downtown Branch',
            'code' => 'DOWNTOWN',
            'is_main_branch' => false,
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

        $downtownManager = User::factory()->create([
            'name' => 'Demo Downtown Manager',
            'email' => 'downtown@demo.test',
            'tenant_id' => $tenant->id,
            'branch_id' => $downtownBranch->id,
        ]);
        $downtownManager->assignRole('Branch Manager');

        // Demo catalog: units of measure, categories, a simple product, and a
        // variant product, so the catalog pages aren't empty on first login.
        $pieceUnit = UnitOfMeasure::create([
            'tenant_id' => $tenant->id,
            'name' => 'Piece',
            'abbreviation' => 'pc',
        ]);

        $cartonUnit = UnitOfMeasure::create([
            'tenant_id' => $tenant->id,
            'name' => 'Carton',
            'abbreviation' => 'ctn',
        ]);

        $beverages = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Beverages',
        ]);

        $apparel = Category::create([
            'tenant_id' => $tenant->id,
            'name' => 'Apparel',
        ]);

        $water = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $beverages->id,
            'name' => 'Bottled Water 500ml',
            'has_variants' => false,
        ]);

        Item::create([
            'tenant_id' => $tenant->id,
            'product_id' => $water->id,
            'sku' => 'WTR-500',
            'base_unit_id' => $pieceUnit->id,
            'purchase_unit_id' => $cartonUnit->id,
            'purchase_unit_factor' => 24,
            'cost_price' => 2.50,
            'selling_price' => 4.00,
            'reorder_level' => 20,
        ]);

        $tshirt = Product::create([
            'tenant_id' => $tenant->id,
            'category_id' => $apparel->id,
            'name' => 'Cotton T-Shirt',
            'has_variants' => true,
        ]);

        Item::create([
            'tenant_id' => $tenant->id,
            'product_id' => $tshirt->id,
            'sku' => 'TSHIRT-S-BLK',
            'variant_attributes' => ['size' => 'S', 'color' => 'Black'],
            'base_unit_id' => $pieceUnit->id,
            'cost_price' => 15,
            'selling_price' => 25,
            'reorder_level' => 10,
        ]);

        Item::create([
            'tenant_id' => $tenant->id,
            'product_id' => $tshirt->id,
            'sku' => 'TSHIRT-M-BLK',
            'variant_attributes' => ['size' => 'M', 'color' => 'Black'],
            'base_unit_id' => $pieceUnit->id,
            'cost_price' => 15,
            'selling_price' => 25,
            'reorder_level' => 10,
        ]);

        Supplier::create([
            'tenant_id' => $tenant->id,
            'name' => 'Accra Wholesale Distributors',
            'contact_person' => 'Kwame Mensah',
            'phone' => '+233 20 123 4567',
            'email' => 'sales@accrawholesale.test',
        ]);
    }
}

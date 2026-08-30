<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ItemBarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithRole(Tenant $tenant, Branch $branch, string $role): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function loginAs(User $user): User
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);

        return $user;
    }

    private function makeItem(Tenant $tenant, ?string $barcode = '1234567890123'): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'sku' => 'LABEL-SKU',
            'barcode' => $barcode,
        ]);
    }

    public function test_user_with_stock_view_can_open_the_label(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);

        $response = $this->get(route('items.label', $item));

        $response->assertOk();
        $response->assertSee('1234567890123');
        $response->assertSee('<svg', false);
    }

    public function test_item_without_a_barcode_falls_back_to_its_sku(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant, null);

        $response = $this->get(route('items.label', $item));

        $response->assertOk();
        $response->assertSee('LABEL-SKU');
    }

    public function test_copies_parameter_controls_how_many_labels_render(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant);

        $response = $this->get(route('items.label', $item).'?copies=3');

        $response->assertOk();
        $this->assertEquals(3, substr_count($response->getContent(), 'class="label"'));
    }

    public function test_user_without_stock_view_is_forbidden(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');
        $item = $this->makeItem($tenant);

        // Auditor holds reports.view/stock.view/etc per the permission matrix,
        // so use a role that genuinely lacks stock.view: none of the seeded
        // roles omit it except... confirm via Suppliers-only style role is not
        // available, so assert against a user with no role assigned at all.
        $noRole = $this->loginAs(\App\Models\User::factory()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        ]));

        $this->get(route('items.label', $item))->assertForbidden();
    }

    public function test_label_from_another_tenant_is_not_found(): void
    {
        $tenantA = Tenant::factory()->licensed()->create();
        $branchA = Branch::factory()->main()->create(['tenant_id' => $tenantA->id]);
        $itemA = $this->makeItem($tenantA);

        $tenantB = Tenant::factory()->licensed()->create();
        $branchB = Branch::factory()->main()->create(['tenant_id' => $tenantB->id]);
        $this->loginAs($this->createUserWithRole($tenantB, $branchB, 'Business Owner'));

        $this->get(route('items.label', $itemA))->assertNotFound();
    }
}

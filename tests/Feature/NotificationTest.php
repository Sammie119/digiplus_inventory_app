<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationTest extends TestCase
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

    private function makeItem(Tenant $tenant, int $reorderLevel = 5): Item
    {
        $category = Category::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $unit = UnitOfMeasure::factory()->create(['tenant_id' => $tenant->id]);

        return Item::factory()->create([
            'tenant_id' => $tenant->id,
            'product_id' => $product->id,
            'base_unit_id' => $unit->id,
            'reorder_level' => $reorderLevel,
        ]);
    }

    public function test_stock_crossing_into_low_notifies_owner_manager_and_store_keeper_only(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $storeKeeper = $this->createUserWithRole($tenant, $branch, 'Store Keeper');
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $auditor = $this->createUserWithRole($tenant, $branch, 'Auditor');

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 10,
        ]);

        // Still above reorder level (6 > 5) — no notification yet.
        $stockLevel->decrement('quantity_on_hand', 4);
        $this->assertEquals(0, Notification::count());

        // Crosses into "low" (4 <= 5).
        $stockLevel->decrement('quantity_on_hand', 2);

        $lowNotifications = Notification::where('type', 'low_stock')->get();
        $this->assertCount(3, $lowNotifications);
        $this->assertEqualsCanonicalizing(
            [$owner->id, $manager->id, $storeKeeper->id],
            $lowNotifications->pluck('user_id')->all()
        );
        $this->assertFalse($lowNotifications->pluck('user_id')->contains($cashier->id));
        $this->assertFalse($lowNotifications->pluck('user_id')->contains($auditor->id));
        $this->assertEquals('low', $lowNotifications->first()->payload['status']);
    }

    public function test_further_decrease_within_the_same_low_band_does_not_renotify(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 8,
        ]);

        // Crosses into "low" (5 <= 5).
        $stockLevel->decrement('quantity_on_hand', 3);
        $this->assertEquals(3, Notification::count());

        // Still "low" — no new notification.
        $stockLevel->decrement('quantity_on_hand', 1);
        $this->assertEquals(3, Notification::count());
    }

    public function test_dropping_from_low_to_out_creates_a_fresh_notification(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 3,
        ]);

        $stockLevel->decrement('quantity_on_hand', 3);

        $notifications = Notification::where('type', 'low_stock')->get();
        $this->assertCount(3, $notifications);
        $this->assertEquals('out', $notifications->first()->payload['status']);
    }

    public function test_stock_increase_never_creates_a_notification(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 1,
        ]);

        $stockLevel->increment('quantity_on_hand', 50);

        $this->assertEquals(0, Notification::count());
    }

    public function test_shift_variance_under_threshold_does_not_notify(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');
        Volt::test('pages.pos.index')->set('closing_float_counted', '110')->call('endShift');

        $shift = Shift::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertEquals(10, $shift->variance);
        $this->assertEquals(0, Notification::count());
    }

    public function test_large_shift_variance_notifies_owner_and_branch_manager_only(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');
        Volt::test('pages.pos.index')->set('closing_float_counted', '150')->call('endShift');

        $notifications = Notification::where('type', 'large_discrepancy')->get();
        $this->assertCount(2, $notifications);
        $this->assertEqualsCanonicalizing([$owner->id, $manager->id], $notifications->pluck('user_id')->all());
        $this->assertEquals(50, $notifications->first()->payload['variance']);
    }

    public function test_user_only_sees_their_own_notifications(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        Notification::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $owner->id,
            'type' => 'low_stock', 'payload' => ['sku' => 'A'],
        ]);
        Notification::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $manager->id,
            'type' => 'low_stock', 'payload' => ['sku' => 'B'],
        ]);

        $rows = Volt::test('pages.notifications.index')->get('notifications');
        $this->assertCount(1, $rows);
        $this->assertEquals($owner->id, $rows->first()->user_id);
    }

    public function test_marking_read_updates_read_at_and_unread_count(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $notification = Notification::create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $owner->id,
            'type' => 'low_stock', 'payload' => ['sku' => 'A'],
        ]);

        $component = Volt::test('layout.notifications');
        $this->assertEquals(1, $component->get('unreadCount'));

        $component->call('open', $notification->id);

        $this->assertEquals(0, $component->get('unreadCount'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_all_read_clears_all_unread_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Notification::factory()->count(3)->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'user_id' => $owner->id,
            'type' => 'low_stock', 'payload' => ['sku' => 'A'],
        ]);

        Volt::test('pages.notifications.index')->call('markAllRead');

        $this->assertEquals(0, Notification::whereNull('read_at')->count());
    }
}

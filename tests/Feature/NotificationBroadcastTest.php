<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Product;
use App\Models\StockLevel;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
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

    public function test_low_stock_crossing_dispatches_one_broadcast_event_per_recipient_on_their_own_channel(): void
    {
        Event::fake([NotificationCreated::class]);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $this->createUserWithRole($tenant, $branch, 'Cashier');

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 10,
        ]);

        $stockLevel->decrement('quantity_on_hand', 6);

        Event::assertDispatchedTimes(NotificationCreated::class, 2);

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($owner) {
            return $event->notification->user_id === $owner->id
                && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$owner->id
                && $event->broadcastAs() === 'notification.created'
                && ! empty($event->broadcastWith()['message']);
        });

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($manager) {
            return $event->notification->user_id === $manager->id
                && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$manager->id;
        });
    }

    public function test_large_discrepancy_dispatches_events_only_for_owner_and_branch_manager(): void
    {
        Event::fake([NotificationCreated::class]);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');
        $this->createUserWithRole($tenant, $branch, 'Store Keeper');

        Volt::test('pages.pos.index')->set('opening_float', '100')->call('startShift');
        Volt::test('pages.pos.index')->set('closing_float_counted', '150')->call('endShift');

        Event::assertDispatchedTimes(NotificationCreated::class, 2);
        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->notification->user_id === $owner->id);
        Event::assertDispatched(NotificationCreated::class, fn (NotificationCreated $event) => $event->notification->user_id === $manager->id);
    }

    public function test_no_broadcast_when_stock_change_does_not_cross_a_status_boundary(): void
    {
        Event::fake([NotificationCreated::class]);

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $item = $this->makeItem($tenant, reorderLevel: 5);
        $stockLevel = StockLevel::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity_on_hand' => 10,
        ]);

        $stockLevel->decrement('quantity_on_hand', 2);

        Event::assertNotDispatched(NotificationCreated::class);
    }
}

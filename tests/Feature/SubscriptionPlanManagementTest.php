<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SubscriptionPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createSuperAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => true,
        ]);
    }

    public function test_super_admin_can_create_a_plan(): void
    {
        $this->actingAs($this->createSuperAdmin());

        Volt::test('pages.super-admin.subscription-plans')
            ->set('name', 'Pro')
            ->set('max_branches', '10')
            ->set('max_users', '30')
            ->set('max_transactions_per_month', '20000')
            ->set('price', '199.00')
            ->call('save')
            ->assertDispatched('toast', message: 'Plan created.', type: 'success');

        $this->assertDatabaseHas('subscription_plans', [
            'name' => 'Pro', 'max_branches' => 10, 'max_users' => 30, 'price' => 199,
        ]);
    }

    public function test_super_admin_can_edit_a_plan(): void
    {
        $this->actingAs($this->createSuperAdmin());
        $plan = SubscriptionPlan::factory()->create(['name' => 'Basic', 'price' => 29]);

        Volt::test('pages.super-admin.subscription-plans')
            ->call('editPlan', $plan->id)
            ->set('price', '39.00')
            ->call('save')
            ->assertDispatched('toast', message: 'Plan updated.', type: 'success');

        $this->assertDatabaseHas('subscription_plans', ['id' => $plan->id, 'price' => 39]);
    }

    public function test_regular_tenant_user_cannot_reach_subscription_plans(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

        $this->actingAs($owner)->get('/super-admin/subscription-plans')->assertForbidden();
    }
}

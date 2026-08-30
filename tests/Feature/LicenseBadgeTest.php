<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LicenseBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(User $user): void
    {
        $this->actingAs($user);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);
        CurrentTenant::set($user->tenant_id);
    }

    public function test_navbar_shows_trial_badge_with_days_remaining(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'trial']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $trialPlan = SubscriptionPlan::factory()->trial()->create();

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $trialPlan->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDays(14)->toDateString(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $this->loginAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Trial')
            ->assertSee('14 days left');
    }

    public function test_navbar_shows_paid_plan_name_for_non_trial_tenant(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $basicPlan = SubscriptionPlan::factory()->create(['name' => 'Basic']);

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $basicPlan->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $this->loginAs($user);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Basic')
            ->assertDontSee('days left');
    }

    /**
     * A lapsed subscription is now blocked at the middleware level before any
     * page (and therefore the navbar) ever renders, so these two cases render
     * the badge component directly rather than hitting an HTTP route.
     */
    public function test_badge_shows_expired_for_a_lapsed_trial(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'trial']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $trialPlan = SubscriptionPlan::factory()->trial()->create();

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $trialPlan->id,
            'period_start' => now()->subDays(20)->toDateString(),
            'period_end' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $this->loginAs($user);

        $html = view('components.license-badge')->render();

        $this->assertStringContainsString('Trial', $html);
        $this->assertStringContainsString('Expired', $html);
        $this->assertStringNotContainsString('days left', $html);
    }

    public function test_badge_shows_expired_for_a_lapsed_paid_plan(): void
    {
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $basicPlan = SubscriptionPlan::factory()->create(['name' => 'Basic']);

        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $basicPlan->id,
            'period_start' => now()->subMonths(2)->toDateString(),
            'period_end' => now()->subDay()->toDateString(),
            'status' => 'active',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $this->loginAs($user);

        $html = view('components.license-badge')->render();

        $this->assertStringContainsString('Basic', $html);
        $this->assertStringContainsString('Expired', $html);
    }
}

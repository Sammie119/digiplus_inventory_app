<?php

namespace Tests\Feature;

use App\Console\Commands\SendSubscriptionRenewalReminders;
use App\Mail\SubscriptionRenewalReminderMail;
use App\Models\Branch;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionRenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(Tenant $tenant, Branch $branch): User
    {
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
        $owner->assignRole('Business Owner');

        return $owner;
    }

    public function test_subscription_expiring_soon_emails_the_owner(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createOwner($tenant, $branch);
        $plan = SubscriptionPlan::factory()->create();

        $dueSoon = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->addDays(3)->toDateString(),
        ]);

        $notDue = TenantSubscription::factory()->create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->addDays(20)->toDateString(),
        ]);

        $this->artisan(SendSubscriptionRenewalReminders::class)->assertSuccessful();

        Mail::assertSent(SubscriptionRenewalReminderMail::class, fn ($mail) => $mail->subscription->is($dueSoon) && $mail->hasTo($owner->email));
        Mail::assertNotSent(SubscriptionRenewalReminderMail::class, fn ($mail) => $mail->subscription->is($notDue));
    }

    public function test_one_tenants_failure_does_not_block_the_next_tenants_reminder(): void
    {
        $brokenTenant = Tenant::factory()->create();
        $brokenBranch = Branch::factory()->main()->create(['tenant_id' => $brokenTenant->id]);
        $brokenOwner = $this->createOwner($brokenTenant, $brokenBranch);
        $plan = SubscriptionPlan::factory()->create();

        $broken = TenantSubscription::factory()->create([
            'tenant_id' => $brokenTenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->addDays(3)->toDateString(),
        ]);

        $healthyTenant = Tenant::factory()->create();
        $healthyBranch = Branch::factory()->main()->create(['tenant_id' => $healthyTenant->id]);
        $healthyOwner = $this->createOwner($healthyTenant, $healthyBranch);

        $healthy = TenantSubscription::factory()->create([
            'tenant_id' => $healthyTenant->id, 'plan_id' => $plan->id, 'status' => 'active',
            'period_end' => now()->addDays(3)->toDateString(),
        ]);

        // Simulate a failure specific to $broken's mail while the healthy
        // tenant's reminder still sends normally -- Mail::fake() can't
        // throw selectively, so this uses a targeted Mockery expectation
        // keyed on the Mailable's own $subscription.
        $healthySendCount = 0;

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andReturnUsing(function ($mailable) use ($broken, &$healthySendCount) {
            if ($mailable->subscription->is($broken)) {
                throw new \RuntimeException('Simulated mail failure');
            }

            $healthySendCount++;

            return true;
        });

        $this->artisan(SendSubscriptionRenewalReminders::class)->assertFailed();

        $this->assertSame(1, $healthySendCount, 'the healthy tenant\'s reminder should still have been sent');
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unverified_user_still_has_full_app_access(): void
    {
        $tenant = Tenant::factory()->licensed()->create(['status' => 'active']);
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        app(RolePermissionSeeder::class)->run();
        app(RolePermissionSeeder::class)->seedForTeam($tenant->id);

        $owner = User::factory()->unverified()->create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
        ]);
        $owner->assignRole('Business Owner');

        $this->assertFalse($owner->hasVerifiedEmail());

        $this->actingAs($owner)->get('/dashboard')->assertOk();
        $this->actingAs($owner)->get('/billing/renew')->assertOk();
    }

    public function test_email_verification_screen_can_be_rendered(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/verify-email');

        $response
            ->assertSeeVolt('pages.auth.verify-email')
            ->assertStatus(200);
    }

    public function test_email_can_be_verified(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)->get($verificationUrl);

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_can_be_verified_behind_a_tls_terminating_proxy(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        // Production signs links as https://, but the proxy forwards the
        // request to PHP over plain http with X-Forwarded-Proto: https.
        URL::forceScheme('https');

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $response = $this->actingAs($user)
            ->withServerVariables(['HTTPS' => 'off', 'REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get(str_replace('https://', 'http://', $verificationUrl));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')]
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}

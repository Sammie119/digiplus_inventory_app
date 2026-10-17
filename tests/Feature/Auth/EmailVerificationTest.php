<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

        $response = $this->actingAs($user)->get($this->verificationUrlFor($user));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_email_can_be_verified_when_php_sees_plain_http(): void
    {
        $user = User::factory()->unverified()->create();

        Event::fake();

        // Production emails https:// links, but PHP behind Nginx may see the
        // request as plain http with no forwarded headers at all.
        URL::forceScheme('https');
        $verificationUrl = $this->verificationUrlFor($user);
        $this->assertStringStartsWith('https://', $verificationUrl);

        $response = $this->actingAs($user)
            ->withServerVariables(['HTTPS' => 'off'])
            ->get(str_replace('https://', 'http://', $verificationUrl));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $response->assertRedirect(route('dashboard', absolute: false).'?verified=1');
    }

    public function test_clients_cannot_spoof_the_host_used_in_generated_urls(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('X-Forwarded-Host', 'attacker.example')
            ->get('/login');

        $response->assertOk()->assertDontSee('attacker.example');
    }

    public function test_a_rejected_verification_link_is_logged(): void
    {
        $user = User::factory()->unverified()->create();

        Log::spy();

        $this->actingAs($user)
            ->get($this->verificationUrlFor($user).'tampered')
            ->assertForbidden();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => $message === 'Rejected signed URL'
                && $context['valid_relative'] === false
                && $context['expired'] === false)
            ->once();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_is_not_verified_with_invalid_hash(): void
    {
        $user = User::factory()->unverified()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('wrong-email')],
            absolute: false,
        );

        $this->actingAs($user)->get($verificationUrl);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    /**
     * The link the real verification email contains.
     */
    private function verificationUrlFor(User $user): string
    {
        return (new VerifyEmail)->toMail($user)->actionUrl;
    }
}

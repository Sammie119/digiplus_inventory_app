<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.register');
    }

    public function test_new_users_can_register(): void
    {
        $component = Volt::test('pages.auth.register')
            ->set('business_name', 'Test Retail Co')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password');

        $component->call('register');

        $component->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_registration_creates_a_tenant_and_main_branch_and_assigns_business_owner(): void
    {
        Volt::test('pages.auth.register')
            ->set('business_name', 'Test Retail Co')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertNotNull($user->tenant_id);
        $this->assertNotNull($user->branch_id);

        $this->assertDatabaseHas('tenants', [
            'id' => $user->tenant_id,
            'name' => 'Test Retail Co',
            'status' => 'trial',
        ]);

        $this->assertDatabaseHas('branches', [
            'tenant_id' => $user->tenant_id,
            'id' => $user->branch_id,
            'code' => 'MAIN',
            'is_main_branch' => true,
        ]);

        $this->assertTrue($user->hasRole('Business Owner'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuperAdminTenantUsageTest extends TestCase
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

    public function test_tenant_list_shows_the_correct_branch_count(): void
    {
        $tenant = Tenant::factory()->create();
        Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        Branch::factory()->create(['tenant_id' => $tenant->id, 'is_main_branch' => false]);

        $this->actingAs($this->createSuperAdmin());

        $rows = Volt::test('pages.super-admin.tenants')->tenants;

        $row = $rows->firstWhere(fn ($row) => $row['tenant']->id === $tenant->id);

        $this->assertSame(2, $row['tenant']->branches_count);
    }
}

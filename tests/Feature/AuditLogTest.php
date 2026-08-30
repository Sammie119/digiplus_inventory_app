<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditLogTest extends TestCase
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

    public function test_creating_an_auditable_model_writes_a_log_entry(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $purchase = Purchase::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'total_cost' => 0,
        ]);

        $log = AuditLog::where('auditable_type', Purchase::class)->where('auditable_id', $purchase->id)->firstOrFail();

        $this->assertEquals('created', $log->action);
        $this->assertEquals($owner->id, $log->user_id);
        $this->assertEquals($tenant->id, $log->tenant_id);
        $this->assertNull($log->old_values);
        $this->assertEquals('draft', $log->new_values['status']);
    }

    public function test_updating_an_auditable_model_logs_only_changed_keys(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $purchase = Purchase::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'total_cost' => 0,
        ]);

        $purchase->update(['status' => 'received', 'received_by' => $owner->id]);

        $log = AuditLog::where('auditable_type', Purchase::class)
            ->where('auditable_id', $purchase->id)
            ->where('action', 'updated')
            ->firstOrFail();

        $this->assertEquals('draft', $log->old_values['status']);
        $this->assertEquals('received', $log->new_values['status']);
        $this->assertArrayNotHasKey('updated_at', $log->new_values);
        $this->assertArrayNotHasKey('supplier_id', $log->new_values);
    }

    public function test_no_op_save_does_not_create_a_log(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        $purchase = Purchase::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'total_cost' => 0,
        ]);

        $countBefore = AuditLog::count();
        $purchase->save();

        $this->assertEquals($countBefore, AuditLog::count());
    }

    public function test_non_auditable_model_does_not_write_logs(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Category::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertEquals(0, AuditLog::count());
    }

    public function test_password_is_never_written_to_the_audit_log(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $target = $this->createUserWithRole($tenant, $branch, 'Cashier');
        $target->update(['password' => bcrypt('new-secret-password')]);

        $log = AuditLog::where('auditable_type', User::class)->where('auditable_id', $target->id)->first();

        // Either no log (nothing else changed) or, if present, no password key.
        if ($log) {
            $this->assertArrayNotHasKey('password', $log->new_values ?? []);
            $this->assertArrayNotHasKey('password', $log->old_values ?? []);
        } else {
            $this->assertNull($log);
        }
    }

    public function test_business_owner_can_view_audit_log(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $this->get('/audit-log')->assertOk()->assertSeeVolt('pages.audit-log.index');
    }

    public function test_branch_manager_cannot_view_audit_log(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $manager = $this->createUserWithRole($tenant, $branch, 'Branch Manager');

        $this->actingAs($manager)->get('/audit-log')->assertForbidden();
    }

    public function test_filters_narrow_the_list(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
        Purchase::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'total_cost' => 0,
        ]);

        $byAction = Volt::test('pages.audit-log.index')->set('action', 'created')->get('logs');
        $this->assertGreaterThan(0, $byAction->count());

        $byWrongAction = Volt::test('pages.audit-log.index')->set('action', 'deleted')->get('logs');
        $this->assertEquals(0, $byWrongAction->count());

        $byModel = Volt::test('pages.audit-log.index')->set('model_type', Purchase::class)->get('logs');
        $this->assertGreaterThan(0, $byModel->count());

        $byOtherModel = Volt::test('pages.audit-log.index')->set('model_type', \App\Models\Sale::class)->get('logs');
        $this->assertEquals(0, $byOtherModel->count());
    }

    public function test_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        $otherTenant = Tenant::factory()->licensed()->create();
        $otherBranch = Branch::factory()->main()->create(['tenant_id' => $otherTenant->id]);
        $otherSupplier = Supplier::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherOwner = $this->createUserWithRole($otherTenant, $otherBranch, 'Business Owner');

        CurrentTenant::set($otherTenant->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        $this->actingAs($otherOwner);
        Purchase::create([
            'tenant_id' => $otherTenant->id,
            'branch_id' => $otherBranch->id,
            'supplier_id' => $otherSupplier->id,
            'status' => 'draft',
            'total_cost' => 0,
        ]);

        $this->loginAs(User::where('tenant_id', $tenant->id)->first());
        $logs = Volt::test('pages.audit-log.index')->get('logs');

        $this->assertEquals(0, $logs->count());
    }
}

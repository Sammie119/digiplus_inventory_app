<?php

namespace Tests\Feature;

use App\Actions\Backups\GenerateTenantBackup;
use App\Console\Commands\RunScheduledTenantBackups;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\TenantBackup;
use App\Models\User;
use App\Support\CurrentTenant;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use ZipArchive;

class TenantBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

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

    public function test_business_owner_can_generate_a_backup_on_demand(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        Category::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Beverages']);

        Volt::test('pages.backups.index')
            ->call('generate')
            ->assertDispatched('toast', message: 'Backup generated.', type: 'success');

        $backup = TenantBackup::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($owner->id, $backup->generated_by);
        $this->assertTrue(Storage::disk('local')->exists($backup->file_path));

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($backup->file_path));
        $this->assertStringContainsString('Beverages', $zip->getFromName('categories.csv'));
        $zip->close();
    }

    public function test_generating_a_backup_handles_items_with_json_variant_attributes(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));
        Item::factory()->create(['tenant_id' => $tenant->id, 'variant_attributes' => ['size' => 'L', 'color' => 'Red']]);

        Volt::test('pages.backups.index')
            ->call('generate')
            ->assertDispatched('toast', message: 'Backup generated.', type: 'success');

        $backup = TenantBackup::where('tenant_id', $tenant->id)->firstOrFail();
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($backup->file_path));
        $this->assertStringContainsString('Red', $zip->getFromName('items.csv'));
        $zip->close();
    }

    public function test_downloading_a_backup_from_another_tenant_is_not_found(): void
    {
        $tenantA = Tenant::factory()->licensed()->create();
        $branchA = Branch::factory()->main()->create(['tenant_id' => $tenantA->id]);
        $this->createUserWithRole($tenantA, $branchA, 'Business Owner');

        CurrentTenant::set($tenantA->id);
        app(GenerateTenantBackup::class)($tenantA->id);
        $backup = TenantBackup::where('tenant_id', $tenantA->id)->firstOrFail();

        $tenantB = Tenant::factory()->licensed()->create();
        $branchB = Branch::factory()->main()->create(['tenant_id' => $tenantB->id]);
        $this->loginAs($this->createUserWithRole($tenantB, $branchB, 'Business Owner'));

        $this->get(route('backups.download', $backup->id))->assertNotFound();
    }

    public function test_cashier_cannot_reach_the_backups_page(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $cashier = $this->createUserWithRole($tenant, $branch, 'Cashier');

        $this->actingAs($cashier)->get('/backups')->assertForbidden();
    }

    public function test_deleting_a_backup_removes_the_row_and_the_file(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $this->loginAs($this->createUserWithRole($tenant, $branch, 'Business Owner'));

        Volt::test('pages.backups.index')->call('generate');
        $backup = TenantBackup::where('tenant_id', $tenant->id)->firstOrFail();

        Volt::test('pages.backups.index')
            ->call('confirmDelete', $backup->id)
            ->call('delete')
            ->assertDispatched('toast', message: 'Backup deleted.', type: 'success');

        $this->assertDatabaseMissing('tenant_backups', ['id' => $backup->id]);
        $this->assertFalse(Storage::disk('local')->exists($backup->file_path));
    }

    public function test_scheduled_command_generates_a_backup_per_tenant(): void
    {
        $tenantA = Tenant::factory()->licensed()->create();
        Branch::factory()->main()->create(['tenant_id' => $tenantA->id]);
        $tenantB = Tenant::factory()->licensed()->create();
        Branch::factory()->main()->create(['tenant_id' => $tenantB->id]);

        $this->artisan(RunScheduledTenantBackups::class)->assertSuccessful();

        $this->assertDatabaseHas('tenant_backups', ['tenant_id' => $tenantA->id, 'generated_by' => null]);
        $this->assertDatabaseHas('tenant_backups', ['tenant_id' => $tenantB->id, 'generated_by' => null]);
    }

    public function test_one_tenants_backup_failure_does_not_block_the_next_tenants_backup(): void
    {
        $brokenTenant = Tenant::factory()->licensed()->create();
        Branch::factory()->main()->create(['tenant_id' => $brokenTenant->id]);
        $healthyTenant = Tenant::factory()->licensed()->create();
        Branch::factory()->main()->create(['tenant_id' => $healthyTenant->id]);

        $brokenTenantId = $brokenTenant->id;

        // Simulate a failure specific to the broken tenant (e.g. a disk
        // error) while the healthy tenant's backup still generates
        // normally -- delegates to the real action for every other tenant.
        $this->app->bind(GenerateTenantBackup::class, fn () => new class($brokenTenantId) extends GenerateTenantBackup
        {
            public function __construct(private int $brokenTenantId) {}

            public function __invoke(int $tenantId, ?int $generatedBy = null): TenantBackup
            {
                if ($tenantId === $this->brokenTenantId) {
                    throw new \RuntimeException('Simulated backup failure');
                }

                return (new GenerateTenantBackup)($tenantId, $generatedBy);
            }
        });

        $this->artisan(RunScheduledTenantBackups::class)->assertFailed();

        $this->assertDatabaseMissing('tenant_backups', ['tenant_id' => $brokenTenant->id]);
        $this->assertDatabaseHas('tenant_backups', ['tenant_id' => $healthyTenant->id, 'generated_by' => null]);
    }

    public function test_generating_an_eleventh_backup_prunes_the_oldest(): void
    {
        $tenant = Tenant::factory()->licensed()->create();
        Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        CurrentTenant::set($tenant->id);

        $generateTenantBackup = app(GenerateTenantBackup::class);
        $first = $generateTenantBackup($tenant->id);

        for ($i = 0; $i < 10; $i++) {
            $this->travel(1)->seconds();
            $generateTenantBackup($tenant->id);
        }

        $this->assertSame(10, TenantBackup::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseMissing('tenant_backups', ['id' => $first->id]);
        $this->assertFalse(Storage::disk('local')->exists($first->file_path));
    }
}

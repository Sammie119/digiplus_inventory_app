<?php

namespace Tests\Feature;

use App\Actions\Backups\GenerateDatabaseBackup;
use App\Console\Commands\RunScheduledDatabaseBackup;
use App\Models\Branch;
use App\Models\DatabaseBackup;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function createSuperAdmin(): User
    {
        return User::factory()->create([
            'tenant_id' => null,
            'branch_id' => null,
            'is_super_admin' => true,
        ]);
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

    public function test_super_admin_can_generate_a_whole_database_backup(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Retail']);
        $admin = $this->createSuperAdmin();
        $this->actingAs($admin);

        Volt::test('pages.super-admin.database-backups')
            ->call('generate')
            ->assertDispatched('toast', message: 'Database backup generated.', type: 'success');

        $backup = DatabaseBackup::firstOrFail();
        $this->assertSame($admin->id, $backup->generated_by);
        $this->assertTrue(Storage::disk('local')->exists($backup->file_path));

        $sql = Storage::disk('local')->get($backup->file_path);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `tenants`;', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `users`;', $sql);
        $this->assertStringContainsString('Acme Retail', $sql);
    }

    public function test_regular_tenant_user_cannot_reach_database_backups(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->main()->create(['tenant_id' => $tenant->id]);
        $owner = $this->createUserWithRole($tenant, $branch, 'Business Owner');

        $this->actingAs($owner)->get('/super-admin/database-backups')->assertForbidden();
    }

    public function test_deleting_a_database_backup_removes_the_row_and_the_file(): void
    {
        $this->actingAs($this->createSuperAdmin());

        Volt::test('pages.super-admin.database-backups')->call('generate');
        $backup = DatabaseBackup::firstOrFail();

        Volt::test('pages.super-admin.database-backups')
            ->call('confirmDelete', $backup->id)
            ->call('delete')
            ->assertDispatched('toast', message: 'Database backup deleted.', type: 'success');

        $this->assertDatabaseMissing('database_backups', ['id' => $backup->id]);
        $this->assertFalse(Storage::disk('local')->exists($backup->file_path));
    }

    public function test_scheduled_command_generates_a_backup_with_no_owner(): void
    {
        $this->artisan(RunScheduledDatabaseBackup::class)->assertSuccessful();

        $this->assertDatabaseHas('database_backups', ['generated_by' => null]);
    }

    public function test_generating_a_fifteenth_backup_prunes_the_oldest(): void
    {
        $generateDatabaseBackup = app(GenerateDatabaseBackup::class);
        $first = $generateDatabaseBackup();

        for ($i = 0; $i < 14; $i++) {
            $this->travel(1)->seconds();
            $generateDatabaseBackup();
        }

        $this->assertSame(14, DatabaseBackup::count());
        $this->assertDatabaseMissing('database_backups', ['id' => $first->id]);
        $this->assertFalse(Storage::disk('local')->exists($first->file_path));
    }
}

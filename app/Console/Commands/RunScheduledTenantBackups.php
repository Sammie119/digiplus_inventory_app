<?php

namespace App\Console\Commands;

use App\Actions\Backups\GenerateTenantBackup;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunScheduledTenantBackups extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Generate a data backup for every tenant';

    public function handle(GenerateTenantBackup $generateTenantBackup): int
    {
        $failures = 0;

        foreach (Tenant::all() as $tenant) {
            try {
                CurrentTenant::set($tenant->id);

                $generateTenantBackup($tenant->id);

                $this->info("Generated backup for {$tenant->name}.");
            } catch (\Throwable $e) {
                $failures++;

                Log::error('Scheduled tenant backup failed', [
                    'tenant_id' => $tenant->id,
                    'exception' => $e,
                ]);

                $this->error("Failed to generate backup for tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                CurrentTenant::clear();
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Actions\Backups\GenerateTenantBackup;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;

class RunScheduledTenantBackups extends Command
{
    protected $signature = 'backups:run-scheduled';

    protected $description = 'Generate a data backup for every tenant';

    public function handle(GenerateTenantBackup $generateTenantBackup): int
    {
        foreach (Tenant::all() as $tenant) {
            CurrentTenant::set($tenant->id);

            $generateTenantBackup($tenant->id);

            CurrentTenant::clear();

            $this->info("Generated backup for {$tenant->name}.");
        }

        return self::SUCCESS;
    }
}

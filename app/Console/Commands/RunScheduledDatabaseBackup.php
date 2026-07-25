<?php

namespace App\Console\Commands;

use App\Actions\Backups\GenerateDatabaseBackup;
use Illuminate\Console\Command;

class RunScheduledDatabaseBackup extends Command
{
    protected $signature = 'database:backup';

    protected $description = 'Generate a full SQL backup of the whole database';

    public function handle(GenerateDatabaseBackup $generateDatabaseBackup): int
    {
        $backup = $generateDatabaseBackup();

        $this->info("Generated database backup ({$backup->file_path}).");

        return self::SUCCESS;
    }
}

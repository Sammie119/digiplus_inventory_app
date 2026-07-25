<?php

namespace App\Actions\Backups;

use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class GenerateDatabaseBackup
{
    private const RETENTION = 14;

    private const CHUNK_SIZE = 500;

    public function __invoke(?int $generatedBy = null): DatabaseBackup
    {
        $disk = Storage::disk('local');
        $relativePath = 'database-backups/'.now()->format('Ymd-His').'.sql';
        $disk->makeDirectory('database-backups');

        $handle = fopen($disk->path($relativePath), 'w');
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($this->tables() as $table) {
            $this->writeTable($handle, $table);
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        $backup = DatabaseBackup::create([
            'file_path' => $relativePath,
            'size_bytes' => $disk->size($relativePath),
            'generated_by' => $generatedBy,
        ]);

        $this->pruneOld($disk);

        return $backup;
    }

    /**
     * @return array<int, string>
     */
    private function tables(): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return array_map(
                fn ($row) => $row->name,
                DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'")
            );
        }

        return array_map(
            fn ($row) => array_values((array) $row)[0],
            DB::select('SHOW TABLES')
        );
    }

    private function createTableSql(string $table): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return DB::select('select sql from sqlite_master where type = ? and name = ?', ['table', $table])[0]->sql;
        }

        return DB::select("SHOW CREATE TABLE `{$table}`")[0]->{'Create Table'};
    }

    /**
     * @param  resource  $handle
     */
    private function writeTable($handle, string $table): void
    {
        fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($handle, $this->createTableSql($table).";\n\n");

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        }

        $query->get()->chunk(self::CHUNK_SIZE)->each(function ($rows) use ($handle, $table) {
            $columns = array_keys((array) $rows->first());
            $columnList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

            $valueLines = $rows->map(function ($row) {
                $values = array_map([$this, 'quote'], array_values((array) $row));

                return '('.implode(', ', $values).')';
            });

            fwrite($handle, "INSERT INTO `{$table}` ({$columnList}) VALUES\n".$valueLines->implode(",\n").";\n");
        });

        fwrite($handle, "\n");
    }

    private function quote(mixed $value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }

    private function pruneOld($disk): void
    {
        $stale = DatabaseBackup::orderByDesc('id')
            ->skip(self::RETENTION)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $old) {
            $disk->delete($old->file_path);
            $old->delete();
        }
    }
}

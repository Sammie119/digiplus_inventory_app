<?php

namespace App\Http\Controllers;

use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupDownloadController extends Controller
{
    public function __invoke(DatabaseBackup $databaseBackup): StreamedResponse
    {
        return Storage::disk('local')->download($databaseBackup->file_path, basename($databaseBackup->file_path));
    }
}

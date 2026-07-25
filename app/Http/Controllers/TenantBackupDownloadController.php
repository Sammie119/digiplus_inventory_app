<?php

namespace App\Http\Controllers;

use App\Models\TenantBackup;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantBackupDownloadController extends Controller
{
    /**
     * Resolves the backup manually (rather than via implicit route-model
     * binding) because binding happens during `SubstituteBindings`, which
     * runs before `SetCurrentTenant` has had a chance to set the tenant
     * context that TenantBackup's TenantScope depends on.
     */
    public function __invoke(string $id): StreamedResponse
    {
        Gate::authorize('tenant-settings.manage');

        $backup = TenantBackup::withoutTenantScope()->where('id', $id)->firstOrFail();

        abort_unless($backup->tenant_id === Auth::user()->tenant_id, 404);

        return Storage::disk('local')->download($backup->file_path, basename($backup->file_path));
    }
}

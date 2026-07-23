<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($model) {
            static::writeAuditLog($model, 'created', null, static::redact($model->getAttributes()));
        });

        static::updated(function ($model) {
            $changes = collect($model->getChanges())->except(array_merge(['updated_at'], static::auditExcept()))->all();

            if (empty($changes)) {
                return;
            }

            $old = collect($model->getOriginal())->only(array_keys($changes))->all();

            static::writeAuditLog($model, 'updated', $old, $changes);
        });

        static::deleted(function ($model) {
            static::writeAuditLog($model, 'deleted', static::redact($model->getOriginal()), null);
        });
    }

    /**
     * Attribute names a model wants kept out of the audit trail entirely
     * (e.g. hashed passwords) — override in the model to extend this.
     */
    protected static function auditExcept(): array
    {
        return [];
    }

    private static function redact(array $attributes): array
    {
        return collect($attributes)->except(static::auditExcept())->all();
    }

    private static function writeAuditLog($model, string $action, ?array $old, ?array $new): void
    {
        $actor = Auth::user();

        if (! $actor) {
            return;
        }

        $tenantId = $model->tenant_id ?? $actor->tenant_id;

        if (! $tenantId) {
            return;
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => static::class,
            'auditable_id' => $model->id,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()?->ip(),
        ]);
    }
}

<?php

namespace App\Support;

use App\Events\NotificationCreated;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\User;

class Notifier
{
    private const LARGE_DISCREPANCY_THRESHOLD = 20.0;

    public static function lowStock(Item $item, int $branchId, string $status, float $onHand, float $reorderLevel): void
    {
        $recipients = User::where('tenant_id', $item->tenant_id)
            ->where(function ($query) use ($branchId) {
                $query->whereHas('roles', fn ($r) => $r->where('name', 'Business Owner'))
                    ->orWhere(function ($q) use ($branchId) {
                        $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['Branch Manager', 'Store Keeper']))
                            ->where('branch_id', $branchId);
                    });
            })
            ->get();

        static::fanOut($recipients, $item->tenant_id, $branchId, 'low_stock', [
            'item_id' => $item->id,
            'sku' => $item->sku,
            'branch_name' => Branch::find($branchId)?->name,
            'status' => $status,
            'on_hand' => $onHand,
            'reorder_level' => $reorderLevel,
        ]);
    }

    public static function largeDiscrepancy(Shift $shift): void
    {
        if (abs((float) $shift->variance) < self::LARGE_DISCREPANCY_THRESHOLD) {
            return;
        }

        $recipients = User::where('tenant_id', $shift->tenant_id)
            ->where(function ($query) use ($shift) {
                $query->whereHas('roles', fn ($r) => $r->where('name', 'Business Owner'))
                    ->orWhere(function ($q) use ($shift) {
                        $q->whereHas('roles', fn ($r) => $r->where('name', 'Branch Manager'))
                            ->where('branch_id', $shift->branch_id);
                    });
            })
            ->get();

        static::fanOut($recipients, $shift->tenant_id, $shift->branch_id, 'large_discrepancy', [
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
            'cashier_name' => $shift->cashier?->name,
            'branch_name' => $shift->branch?->name,
            'variance' => (float) $shift->variance,
        ]);
    }

    private static function fanOut($recipients, int $tenantId, int $branchId, string $type, array $payload): void
    {
        foreach ($recipients as $recipient) {
            $notification = Notification::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $recipient->id,
                'type' => $type,
                'payload' => $payload,
            ]);

            NotificationCreated::dispatch($notification);
        }
    }
}

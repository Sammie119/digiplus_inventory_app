<?php

namespace App\Support;

use App\Models\Notification;

class NotificationPresenter
{
    public static function message(Notification $notification): string
    {
        $payload = $notification->payload ?? [];

        return match ($notification->type) {
            'low_stock' => ($payload['status'] ?? null) === 'out'
                ? __(':sku is out of stock at :branch.', ['sku' => $payload['sku'] ?? '—', 'branch' => $payload['branch_name'] ?? '—'])
                : __(':sku is low on stock at :branch (:on_hand left, reorder at :reorder).', [
                    'sku' => $payload['sku'] ?? '—',
                    'branch' => $payload['branch_name'] ?? '—',
                    'on_hand' => rtrim(rtrim(number_format((float) ($payload['on_hand'] ?? 0), 2), '0'), '.'),
                    'reorder' => rtrim(rtrim(number_format((float) ($payload['reorder_level'] ?? 0), 2), '0'), '.'),
                ]),
            'large_discrepancy' => __('Shift variance of :variance for :cashier at :branch.', [
                'variance' => number_format((float) ($payload['variance'] ?? 0), 2),
                'cashier' => $payload['cashier_name'] ?? '—',
                'branch' => $payload['branch_name'] ?? '—',
            ]),
            default => __('New notification.'),
        };
    }

    public static function linkRoute(Notification $notification): ?string
    {
        return match ($notification->type) {
            'low_stock' => 'stock-levels.index',
            'large_discrepancy' => 'reports.shifts',
            default => null,
        };
    }
}

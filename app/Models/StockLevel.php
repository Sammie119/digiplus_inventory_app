<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Notifier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updated(function (StockLevel $model) {
            if (! array_key_exists('quantity_on_hand', $model->getChanges())) {
                return;
            }

            $delta = (float) $model->quantity_on_hand - (float) $model->getOriginal('quantity_on_hand');
            $totalAfter = (float) static::where('item_id', $model->item_id)->where('branch_id', $model->branch_id)->sum('quantity_on_hand');
            $totalBefore = $totalAfter - $delta;

            $item = $model->item;
            $reorderLevel = $item->reorderLevelForBranch($model->branch_id);

            $statusFor = fn ($qty) => $qty <= 0 ? 'out' : ($qty <= $reorderLevel ? 'low' : 'ok');
            $severity = ['ok' => 0, 'low' => 1, 'out' => 2];
            $before = $statusFor($totalBefore);
            $after = $statusFor($totalAfter);

            if ($severity[$after] > $severity[$before]) {
                Notifier::lowStock($item, $model->branch_id, $after, $totalAfter, $reorderLevel);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'quantity_in_transit' => 'decimal:3',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}

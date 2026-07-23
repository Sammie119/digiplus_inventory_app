<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'cost_at_sale' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    /**
     * Quantity still eligible to be returned — the sold quantity minus
     * whatever's already reserved by a pending or approved return, so two
     * concurrent return requests can't together exceed what was sold.
     */
    public function remainingReturnableQuantity(): float
    {
        $reserved = $this->returnItems()
            ->whereHas('saleReturn', fn ($q) => $q->whereIn('status', ['pending', 'approved']))
            ->sum('quantity_returned');

        return max(0, (float) $this->quantity - (float) $reserved);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'units_of_measure';

    protected $guarded = ['id'];

    public function itemsAsBaseUnit(): HasMany
    {
        return $this->hasMany(Item::class, 'base_unit_id');
    }

    public function itemsAsPurchaseUnit(): HasMany
    {
        return $this->hasMany(Item::class, 'purchase_unit_id');
    }
}

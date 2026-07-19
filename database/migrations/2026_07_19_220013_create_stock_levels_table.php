<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('quantity_on_hand', 14, 3)->default(0);
            $table->decimal('quantity_in_transit', 14, 3)->default(0); // FR-1.4
            $table->timestamps();

            $table->unique(['branch_id', 'item_id', 'batch_id'], 'stock_levels_branch_item_batch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 12, 2); // drives FR-1.10 weighted-average recalculation
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('purchase_unit_quantity', 14, 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};

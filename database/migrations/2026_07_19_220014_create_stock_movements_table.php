<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type'); // purchase|sale|adjustment|transfer_in|transfer_out|return_in|return_out
            $table->decimal('quantity', 14, 3); // signed
            $table->decimal('unit_cost', 12, 2); // basis for weighted-average recalculation, FR-1.10
            $table->string('reference_type'); // polymorphic: purchase, sale, adjustment, transfer, return
            $table->unsignedBigInteger('reference_id');
            $table->string('reason_code')->nullable();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['branch_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};

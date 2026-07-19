<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku');
            $table->string('barcode')->nullable();
            $table->json('variant_attributes')->nullable(); // {"size": "L", "color": "Red"}
            $table->foreignId('base_unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('purchase_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('purchase_unit_factor', 12, 3)->nullable(); // e.g. 24 pieces per carton
            $table->decimal('cost_price', 12, 2)->default(0); // weighted average, FR-1.10
            $table->decimal('selling_price', 12, 2);
            $table->unsignedInteger('reorder_level')->default(0); // tenant-level default
            $table->boolean('track_batches')->default(false); // FR-1.8
            $table->string('status')->default('active'); // active|discontinued
            $table->string('image')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

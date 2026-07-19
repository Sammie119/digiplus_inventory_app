<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number');
            $table->date('expiry_date')->nullable(); // FR-1.8
            $table->date('received_date')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'batch_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('opening_float', 12, 2); // FR-2.7
            $table->timestamp('opened_at');
            $table->decimal('closing_float_counted', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable(); // computed at close
            $table->decimal('variance', 12, 2)->nullable(); // computed at close
            $table->string('status')->default('open'); // open|closed
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};

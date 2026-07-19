<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number'); // e.g. BR01-2026-000123, FR-2.8
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2);
            $table->string('status')->default('completed'); // completed|void
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->foreignId('void_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sync_source')->default('online'); // online|offline, FR-5.6
            $table->timestamp('synced_at')->nullable();
            $table->boolean('offline_flagged_oversell')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};

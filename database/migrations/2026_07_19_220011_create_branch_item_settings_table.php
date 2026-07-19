<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_item_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('reorder_level'); // overrides items.reorder_level, FR-1.9
            $table->timestamps();

            $table->unique(['branch_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_item_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('property_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('adjusts_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            $table->decimal('noticed_value', 14, 2);
            $table->decimal('final_value', 14, 2);
            $table->decimal('savings', 12, 2);
            $table->decimal('fee_rate', 5, 4);
            $table->decimal('fee', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->json('breakdown');
            $table->index('invoice_id');
            $table->index('property_id');
            $table->index('adjusts_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};

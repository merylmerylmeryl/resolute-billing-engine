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
        Schema::create('jurisdictions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->decimal('rate', 8, 4);
            $table->enum('exemption_type', ['none', 'flat', 'percent']);
            $table->decimal('exemption_amount', 12, 4)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jurisdictions');
    }
};

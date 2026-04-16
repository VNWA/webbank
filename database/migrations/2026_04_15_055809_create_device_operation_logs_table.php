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
        Schema::create('device_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_operation_id')->constrained('device_operations')->cascadeOnDelete();
            $table->string('level', 20)->default('info');
            $table->string('stage', 80);
            $table->text('message');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_operation_logs');
    }
};

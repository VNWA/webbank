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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['normal', 'pending'])->default('normal');
            $table->string('duo_api_key');
            $table->string('image_id');
            $table->string('name');
            $table->string('pg_pass');
            $table->string('pg_pin');
            $table->string('baca_pass');
            $table->string('baca_pin');
            $table->string('pg_video_id');
            $table->string('baca_video_id');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};

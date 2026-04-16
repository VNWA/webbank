<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_operation_id')->nullable()->constrained('device_operations')->nullOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 12);
            $table->string('bank_name', 255)->nullable();
            $table->string('account_number', 64);
            $table->string('recipient_name', 255)->nullable();
            $table->unsignedBigInteger('amount');
            $table->string('transfer_note', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('device_operation_id');
            $table->index(['device_id', 'created_at']);
            $table->index(['channel', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_histories');
    }
};

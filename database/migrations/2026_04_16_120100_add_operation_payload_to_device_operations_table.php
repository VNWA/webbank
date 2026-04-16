<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_operations', function (Blueprint $table) {
            $table->json('operation_payload')->nullable()->after('operation_type');
        });
    }

    public function down(): void
    {
        Schema::table('device_operations', function (Blueprint $table) {
            $table->dropColumn('operation_payload');
        });
    }
};


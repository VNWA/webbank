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
        if (Schema::hasColumn('devices', 'pg_smart_otp')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->dropColumn('pg_smart_otp');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('devices', 'pg_smart_otp')) {
            Schema::table('devices', function (Blueprint $table) {
                $table->string('pg_smart_otp')->nullable()->after('pg_pin');
            });
        }
    }
};

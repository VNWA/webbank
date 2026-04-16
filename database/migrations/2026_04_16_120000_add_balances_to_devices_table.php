<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('pg_balance', 18, 2)->nullable()->after('baca_video_id');
            $table->decimal('baca_balance', 18, 2)->nullable()->after('pg_balance');
            $table->timestamp('pg_balance_updated_at')->nullable()->after('baca_balance');
            $table->timestamp('baca_balance_updated_at')->nullable()->after('pg_balance_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'pg_balance',
                'baca_balance',
                'pg_balance_updated_at',
                'baca_balance_updated_at',
            ]);
        });
    }
};


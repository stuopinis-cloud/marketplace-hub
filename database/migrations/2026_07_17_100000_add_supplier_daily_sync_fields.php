<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->boolean('force_daily_sync')->default(false)->after('sync_interval_minutes');
            $table->text('last_sync_message')->nullable()->after('last_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['force_daily_sync', 'last_sync_message']);
        });
    }
};

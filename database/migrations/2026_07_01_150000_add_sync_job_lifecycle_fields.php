<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('finished_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_requested_at');
            $table->timestamp('heartbeat_at')->nullable()->after('cancelled_at');
            $table->unsignedBigInteger('process_id')->nullable()->after('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('sync_jobs', function (Blueprint $table) {
            $table->dropColumn([
                'cancel_requested_at',
                'cancelled_at',
                'heartbeat_at',
                'process_id',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('connector_type')->nullable()->after('enabled');
            $table->text('endpoint_url')->nullable()->after('connector_type');
            $table->string('auth_type')->default('none')->after('endpoint_url');
            $table->text('credentials')->nullable()->after('auth_type');
            $table->unsignedInteger('stock_priority')->default(100)->after('credentials');
            $table->string('in_stock_delivery_text')->nullable()->after('stock_priority');
            $table->string('backorder_delivery_text')->nullable()->after('in_stock_delivery_text');
            $table->boolean('allow_backorder_export')->default(false)->after('backorder_delivery_text');
            $table->boolean('sync_enabled')->default(false)->after('allow_backorder_export');
            $table->unsignedInteger('sync_interval_minutes')->nullable()->after('sync_enabled');
            $table->unsignedInteger('stale_after_minutes')->nullable()->after('sync_interval_minutes');
            $table->timestamp('last_sync_at')->nullable()->after('stale_after_minutes');
            $table->string('last_sync_status')->nullable()->after('last_sync_at');
            $table->json('config')->nullable()->after('last_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn([
                'connector_type',
                'endpoint_url',
                'auth_type',
                'credentials',
                'stock_priority',
                'in_stock_delivery_text',
                'backorder_delivery_text',
                'allow_backorder_export',
                'sync_enabled',
                'sync_interval_minutes',
                'stale_after_minutes',
                'last_sync_at',
                'last_sync_status',
                'config',
            ]);
        });
    }
};

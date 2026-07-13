<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->string('availability_status')->nullable()->after('stock_quantity');
            $table->json('raw_payload')->nullable()->after('availability_status');
            $table->string('match_status')->nullable()->after('raw_payload');
            $table->string('match_method')->nullable()->after('match_status');
            $table->timestamp('last_seen_at')->nullable()->after('last_synced_at');
            $table->timestamp('stale_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropColumn([
                'availability_status',
                'raw_payload',
                'match_status',
                'match_method',
                'last_seen_at',
                'stale_at',
            ]);
        });
    }
};

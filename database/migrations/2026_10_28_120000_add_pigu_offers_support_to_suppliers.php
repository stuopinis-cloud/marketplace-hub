<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->string('barcode')->nullable()->after('supplier_sku');
            $table->string('title')->nullable()->after('barcode');

            $table->index(['supplier_id', 'barcode']);
        });

        Schema::table('automation_schedules', function (Blueprint $table): void {
            $table->boolean('run_pigu_offers_export')->default(false)->after('run_varle_export');
        });
    }

    public function down(): void
    {
        Schema::table('automation_schedules', function (Blueprint $table): void {
            $table->dropColumn('run_pigu_offers_export');
        });

        Schema::table('supplier_products', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id', 'barcode']);
            $table->dropColumn(['barcode', 'title']);
        });
    }
};

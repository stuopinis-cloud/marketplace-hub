<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('availability_fallback_quantity')->default(5)->after('allow_backorder_export');
        });

        Schema::table('supplier_products', function (Blueprint $table) {
            $table->integer('stock_quantity')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_products', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->nullable(false)->change();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('availability_fallback_quantity');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('varle_export_status')->default('pending_review')->after('status');
        });

        DB::table('products')->update(['varle_export_status' => 'auto']);

        Schema::table('category_mappings', function (Blueprint $table) {
            $table->boolean('export_enabled')->default(true)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('category_mappings', function (Blueprint $table) {
            $table->dropColumn('export_enabled');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('varle_export_status');
        });
    }
};

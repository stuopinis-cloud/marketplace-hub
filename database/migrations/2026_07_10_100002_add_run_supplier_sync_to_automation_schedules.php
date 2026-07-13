<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_schedules', function (Blueprint $table): void {
            $table->boolean('run_supplier_sync')->default(false)->after('run_shopify_import');
        });
    }

    public function down(): void
    {
        Schema::table('automation_schedules', function (Blueprint $table): void {
            $table->dropColumn('run_supplier_sync');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_mappings', function (Blueprint $table) {
            $table->string('target_category_id')->nullable()->after('target_category_path');
            $table->string('target_category_name')->nullable()->after('target_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('category_mappings', function (Blueprint $table) {
            $table->dropColumn(['target_category_id', 'target_category_name']);
        });
    }
};

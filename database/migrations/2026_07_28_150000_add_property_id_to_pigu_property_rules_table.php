<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pigu_property_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('property_id')->nullable()->after('property_name');
        });
    }

    public function down(): void
    {
        Schema::table('pigu_property_rules', function (Blueprint $table) {
            $table->dropColumn('property_id');
        });
    }
};

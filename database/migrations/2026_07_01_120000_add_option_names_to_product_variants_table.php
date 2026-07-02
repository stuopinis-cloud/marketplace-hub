<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('option1_name')->nullable()->after('option1');
            $table->string('option1_value')->nullable()->after('option1_name');
            $table->string('option2_name')->nullable()->after('option2');
            $table->string('option2_value')->nullable()->after('option2_name');
            $table->string('option3_name')->nullable()->after('option3');
            $table->string('option3_value')->nullable()->after('option3_name');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn([
                'option1_name',
                'option1_value',
                'option2_name',
                'option2_value',
                'option3_name',
                'option3_value',
            ]);
        });
    }
};

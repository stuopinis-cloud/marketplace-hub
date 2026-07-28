<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pigu_dimension_rules', function (Blueprint $table) {
            $table->id();
            $table->string('match_type'); // collection|product_type|tag|manual
            $table->string('match_value');
            $table->decimal('weight_kg', 10, 4);
            $table->decimal('length_m', 10, 4);
            $table->decimal('width_m', 10, 4);
            $table->decimal('height_m', 10, 4);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->unique(['match_type', 'match_value'], 'pigu_dimension_rules_match_unique');
            $table->index(['enabled', 'priority']);
        });

        Schema::create('pigu_property_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pigu_category_id')->index();
            $table->string('match_type');
            $table->string('match_value');
            $table->string('property_name');
            $table->string('value_source'); // vendor|fixed
            $table->string('value_name')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->timestamps();

            $table->unique(
                ['pigu_category_id', 'match_type', 'match_value', 'property_name'],
                'pigu_property_rules_unique',
            );
            $table->index(['enabled', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pigu_property_rules');
        Schema::dropIfExists('pigu_dimension_rules');
    }
};

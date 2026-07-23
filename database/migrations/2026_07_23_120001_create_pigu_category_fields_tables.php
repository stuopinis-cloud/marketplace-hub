<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pigu_category_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lowest_category_id')->index();
            $table->string('lowest_category_title')->nullable();
            $table->string('lowest_category_title_en')->nullable();
            $table->string('top_category_title')->nullable();
            $table->string('top_category_title_en')->nullable();
            $table->unsignedBigInteger('field_id')->index();
            $table->string('field_title')->nullable();
            $table->string('field_title_en')->nullable();
            $table->boolean('mandatory')->default(false);
            $table->string('field_value_type')->nullable();
            $table->text('lt_values')->nullable();
            $table->text('lv_values')->nullable();
            $table->text('ee_values')->nullable();
            $table->text('fi_values')->nullable();
            $table->text('en_values')->nullable();
            $table->timestamps();

            $table->unique(['lowest_category_id', 'field_id']);
        });

        Schema::create('pigu_property_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lowest_category_id')->nullable()->index();
            $table->unsignedBigInteger('field_id')->index();
            $table->string('source_type'); // vendor|color|size|product_type|material|manual
            $table->string('source_key')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['lowest_category_id', 'field_id', 'source_type', 'source_key'], 'pigu_property_mappings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pigu_property_mappings');
        Schema::dropIfExists('pigu_category_fields');
    }
};

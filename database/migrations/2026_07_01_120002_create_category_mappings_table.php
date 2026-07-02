<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_channel_id')->constrained()->cascadeOnDelete();
            $table->string('source_type')->index();
            $table->string('source_value')->index();
            $table->string('target_category_path');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['marketplace_channel_id', 'source_type', 'source_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_mappings');
    }
};

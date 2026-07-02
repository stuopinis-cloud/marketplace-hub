<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->index();
            $table->string('external_id')->nullable()->index();
            $table->string('name');
            $table->string('handle')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'type', 'name']);
            $table->index(['source_id', 'type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_categories');
    }
};

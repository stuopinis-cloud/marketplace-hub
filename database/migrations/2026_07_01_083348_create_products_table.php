<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->index();
            $table->string('title');
            $table->longText('description_html')->nullable();
            $table->string('vendor')->nullable();
            $table->string('brand')->nullable();
            $table->string('product_type')->nullable();
            $table->string('category')->nullable();
            $table->string('status')->default('active');
            $table->string('handle')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

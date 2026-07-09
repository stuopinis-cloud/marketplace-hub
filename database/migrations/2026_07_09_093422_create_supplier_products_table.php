<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('supplier_sku');
            $table->integer('stock_quantity')->default(0);
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_sku']);
            $table->index('product_variant_id');
            $table->index(['enabled', 'stock_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};

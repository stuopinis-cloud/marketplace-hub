<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_delivery_rules', function (Blueprint $table) {
            $table->id();
            $table->string('vendor')->index();
            $table->boolean('enabled')->default(true);
            $table->string('in_stock_delivery_text')->default('1-2 d.d.');
            $table->string('backorder_delivery_text')->default('5-10 d.d.');
            $table->boolean('allow_backorder_export')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('inventory_policy')->nullable()->after('image_external_id');
            $table->boolean('backorder_allowed')->default(false)->after('inventory_policy');
            $table->index('inventory_policy');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('varle_is_ready')->nullable()->index();
            $table->unsignedSmallInteger('varle_issue_count')->default(0);
            $table->json('varle_issue_codes')->nullable();
            $table->string('varle_barcode_status')->nullable();
            $table->string('varle_image_status')->nullable();
            $table->string('varle_category_status')->nullable();
            $table->string('varle_stock_status')->nullable();
            $table->string('varle_vendor_delivery_rule_status')->nullable();
            $table->string('varle_delivery_text_preview')->nullable();
            $table->string('varle_mapped_category_preview')->nullable();
            $table->unsignedSmallInteger('varle_exportable_variants_count')->default(0);
            $table->unsignedSmallInteger('varle_skipped_variants_count')->default(0);
            $table->timestamp('varle_readiness_cached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'varle_is_ready',
                'varle_issue_count',
                'varle_issue_codes',
                'varle_barcode_status',
                'varle_image_status',
                'varle_category_status',
                'varle_stock_status',
                'varle_vendor_delivery_rule_status',
                'varle_delivery_text_preview',
                'varle_mapped_category_preview',
                'varle_exportable_variants_count',
                'varle_skipped_variants_count',
                'varle_readiness_cached_at',
            ]);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['inventory_policy']);
            $table->dropColumn(['inventory_policy', 'backorder_allowed']);
        });

        Schema::dropIfExists('vendor_delivery_rules');
    }
};

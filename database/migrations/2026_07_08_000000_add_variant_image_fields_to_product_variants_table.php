<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('raw_payload');
            $table->text('image_alt')->nullable()->after('image_url');
            $table->string('image_external_id')->nullable()->after('image_alt');
            $table->index('image_external_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropIndex(['image_external_id']);
            $table->dropColumn([
                'image_url',
                'image_alt',
                'image_external_id',
            ]);
        });
    }
};

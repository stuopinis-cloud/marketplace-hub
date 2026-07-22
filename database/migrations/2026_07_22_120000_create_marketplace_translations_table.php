<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_translations', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('translatable');
            $table->string('marketplace')->nullable()->index();
            $table->string('locale', 16)->index();
            $table->string('field')->index();
            $table->string('source_text_hash', 64)->index();
            $table->text('source_text');
            $table->longText('translated_text')->nullable();
            $table->string('status')->default('missing')->index();
            $table->string('provider')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('translated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['translatable_type', 'translatable_id', 'marketplace', 'locale', 'field', 'source_text_hash'],
                'marketplace_translations_unique_entity_field',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_translations');
    }
};

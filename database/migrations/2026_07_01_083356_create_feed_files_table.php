<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_channel_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->text('public_url')->nullable();
            $table->string('status')->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_files');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->index();
            $table->boolean('enabled')->default(false);
            $table->string('frequency')->default('daily');
            $table->time('run_time')->nullable();
            $table->string('timezone')->default('Europe/Vilnius');
            $table->boolean('run_shopify_import')->default(true);
            $table->boolean('run_varle_export')->default(true);
            $table->boolean('generate_failed_csv')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_schedules');
    }
};

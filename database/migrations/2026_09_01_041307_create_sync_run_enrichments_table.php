<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_run_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('task_key');
            $table->string('type', 24);
            $table->string('status', 24)->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('error_category', 32)->nullable()->index();
            $table->string('provider_error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['sync_run_id', 'task_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_run_enrichments');
    }
};

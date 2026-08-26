<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrar_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 24)->index();
            $table->string('environment', 16)->default('SANDBOX');
            $table->string('label')->unique();
            $table->string('username');
            $table->string('api_user')->nullable();
            $table->string('client_ipv4', 45)->nullable();
            $table->text('credentials');
            $table->boolean('is_active')->default(true)->index();
            $table->string('last_test_status', 24)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registrar_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->index();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registrar_account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253);
            $table->json('nameservers');
            $table->string('remote_status', 80)->nullable();
            $table->string('inventory_status', 24)->default('AVAILABLE')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('nameservers_observed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['registrar_account_id', 'name']);
            $table->index('name');
        });

        Schema::create('nameserver_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('nameservers');
            $table->timestamps();
        });

        Schema::create('bulk_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_bulk_change_id')->nullable()->constrained('bulk_changes')->nullOnDelete();
            $table->string('type', 16);
            $table->json('target_nameservers')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('processing_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('conflict_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);
            $table->uuid('job_batch_id')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bulk_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bulk_change_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->string('preview_disposition', 24);
            $table->string('status', 24)->nullable()->index();
            $table->json('preview_nameservers');
            $table->json('old_nameservers')->nullable();
            $table->json('target_nameservers');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('error_category', 32)->nullable()->index();
            $table->string('provider_error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['bulk_change_id', 'domain_id']);
        });

        Schema::create('domain_mutation_reservations', function (Blueprint $table) {
            $table->foreignId('domain_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('bulk_change_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event')->index();
            $table->nullableMorphs('subject');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('domain_mutation_reservations');
        Schema::dropIfExists('bulk_change_items');
        Schema::dropIfExists('bulk_changes');
        Schema::dropIfExists('nameserver_presets');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('registrar_accounts');
    }
};

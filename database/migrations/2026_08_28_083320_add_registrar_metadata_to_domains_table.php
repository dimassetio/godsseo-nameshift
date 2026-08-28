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
        Schema::table('domains', function (Blueprint $table) {
            $table->string('tld', 100)->nullable()->after('name');
            $table->decimal('renewal_price', 10, 2)->nullable()->after('tld');
            $table->timestamp('registered_at')->nullable()->after('renewal_price');
            $table->timestamp('expires_at')->nullable()->index()->after('registered_at');
            $table->boolean('is_locked')->nullable()->after('remote_status');
            $table->boolean('privacy_enabled')->nullable()->after('is_locked');
            $table->boolean('auto_renew')->nullable()->after('privacy_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn([
                'tld',
                'renewal_price',
                'registered_at',
                'expires_at',
                'is_locked',
                'privacy_enabled',
                'auto_renew',
            ]);
        });
    }
};

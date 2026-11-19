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
        Schema::table('email_analyses', function (Blueprint $table): void {
            $table->uuid('notification_token')->nullable()->unique();
            $table->timestamp('notification_claimed_at')->nullable();
            $table->index(['notified_at', 'notification_claimed_at'], 'email_analysis_delivery_claim_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_analyses', function (Blueprint $table): void {
            $table->dropIndex('email_analysis_delivery_claim_index');
            $table->dropUnique(['notification_token']);
            $table->dropColumn(['notification_token', 'notification_claimed_at']);
        });
    }
};

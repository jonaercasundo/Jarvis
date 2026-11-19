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
        Schema::create('email_analyses', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('account');
            $table->string('message_id');
            $table->string('thread_id')->nullable();
            $table->string('from_address')->nullable();
            $table->text('subject')->nullable();
            $table->string('classification', 40);
            $table->string('priority', 16);
            $table->boolean('requires_action');
            $table->boolean('time_sensitive');
            $table->boolean('business_impact');
            $table->boolean('notification_required')->index();
            $table->text('reason');
            $table->text('action')->nullable();
            $table->string('deadline')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('analyzed_at');
            $table->timestamp('notified_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['provider', 'account', 'message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_analyses');
    }
};

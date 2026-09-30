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
        Schema::create('jarvis_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->unique();
            $table->string('ai_name', 30)->default('JC');
            $table->string('owner_name', 60)->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->string('api_key_hint', 20)->nullable();
            $table->json('allowed_actions')->nullable();
            $table->boolean('confirm_before_send')->default(true);
            $table->string('tone', 20)->default('professional');
            $table->unsignedInteger('daily_credit_limit')->default(0);
            $table->text('scope_of_work')->nullable();
            $table->text('limitations')->nullable();
            $table->string('workflow_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jarvis_settings');
    }
};

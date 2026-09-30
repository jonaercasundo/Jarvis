<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_email_actions', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('action'); // 'reply', 'delete'
            $table->string('email_id');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed, expired
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_email_actions');
    }
};

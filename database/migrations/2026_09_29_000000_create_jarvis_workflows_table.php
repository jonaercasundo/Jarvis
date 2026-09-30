<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('owner_key', 128);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('workflow_data');
            $table->json('trigger_state')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('webhook_secret')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['owner_key', 'status']);
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarvis_workflows');
    }
};

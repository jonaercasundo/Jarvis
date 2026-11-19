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
        Schema::create('jarvis_workflow_execution_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('execution_id')->constrained('jarvis_workflow_executions')->cascadeOnDelete();
            $table->string('node_id', 80);
            $table->string('node_type', 40);
            $table->unsignedSmallInteger('execution_order');
            $table->string('status', 20)->default('running');
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['execution_id', 'execution_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jarvis_workflow_execution_steps');
    }
};

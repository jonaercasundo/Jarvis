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
        Schema::create('jarvis_workflow_agent_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('jarvis_workflows')->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained('jarvis_workflow_executions')->cascadeOnDelete();
            $table->string('agent_node_id', 80);
            $table->string('task_type', 80)->default('workflow_execution');
            $table->string('status', 24)->default('queued');
            $table->unsignedTinyInteger('priority')->default(50);
            $table->json('input_data')->nullable();
            $table->json('result_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['execution_id', 'agent_node_id']);
            $table->index(['workflow_id', 'agent_node_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jarvis_workflow_agent_tasks');
    }
};

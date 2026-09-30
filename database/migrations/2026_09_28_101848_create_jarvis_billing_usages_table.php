<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_billing_usages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('jarvis_billing_id')
                ->constrained('jarvis_billing')
                ->cascadeOnDelete();

            $table->string('provider')->default('anthropic');

            $table->string('model')->nullable();

            $table->string('request_id')->nullable();

            $table->unsignedBigInteger('input_tokens')->default(0);

            $table->unsignedBigInteger('output_tokens')->default(0);

            $table->unsignedBigInteger('total_tokens')->default(0);

            $table->decimal('cost_usd', 12, 8)->default(0);

            $table->timestamp('used_at');

            $table->timestamps();

            $table->index('request_id');
            $table->index('used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarvis_billing_usages');
    }
};

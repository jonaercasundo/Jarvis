<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_billing', function (Blueprint $table) {
            $table->id();

            $table->string('provider')->default('anthropic');

            $table->decimal('starting_balance_usd', 12, 6)
                ->default(0);

            $table->decimal('current_balance_usd', 12, 6)
                ->default(0);

            $table->decimal('total_spent_usd', 12, 6)
                ->default(0);

            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarvis_billing');
    }
};

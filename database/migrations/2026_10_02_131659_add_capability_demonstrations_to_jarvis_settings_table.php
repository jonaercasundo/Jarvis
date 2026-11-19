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
        Schema::table('jarvis_settings', function (Blueprint $table) {
            $table->string('capability_demonstrations', 30)->default('ask_first');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jarvis_settings', function (Blueprint $table) {
            $table->dropColumn('capability_demonstrations');
        });
    }
};

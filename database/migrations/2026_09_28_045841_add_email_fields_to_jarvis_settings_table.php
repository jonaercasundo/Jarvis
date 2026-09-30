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
            $table->string('email_address')->nullable();
            $table->text('email_password_encrypted')->nullable();
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 10)->nullable();
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jarvis_settings', function (Blueprint $table) {
            $table->dropColumn([
                'email_address', 'email_password_encrypted',
                'imap_host', 'imap_port', 'imap_encryption',
                'smtp_host', 'smtp_port', 'smtp_encryption',
            ]);
        });
    }
};

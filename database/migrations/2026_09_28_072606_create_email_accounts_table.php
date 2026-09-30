<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email')->unique();

            // IMAP
            $table->string('imap_host');
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl');
            $table->string('imap_username');
            $table->text('imap_password');

            // SMTP
            $table->string('smtp_host');
            $table->unsignedInteger('smtp_port')->default(465);
            $table->string('smtp_encryption')->default('ssl');
            $table->string('smtp_username');
            $table->text('smtp_password');

            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};

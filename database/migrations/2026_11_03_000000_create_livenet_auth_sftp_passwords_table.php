<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remembers which people have chosen an SFTP password, so the panel knows whether to ask for one.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('livenet_auth_sftp_passwords')) {
            return;
        }

        Schema::create('livenet_auth_sftp_passwords', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('set_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('livenet_auth_sftp_passwords');
    }
};

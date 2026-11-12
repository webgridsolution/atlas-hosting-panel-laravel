<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('description')->nullable();

            // Limits are stored in the same units the agent validates against,
            // so the UI never has to convert to create an account.
            $table->unsignedInteger('disk_mb');
            $table->unsignedInteger('inodes');
            $table->unsignedInteger('bandwidth_mb');
            $table->unsignedSmallInteger('max_domains')->default(1);
            $table->unsignedSmallInteger('max_databases')->default(1);
            $table->unsignedSmallInteger('max_mailboxes')->default(1);
            $table->string('php_version', 8)->default('8.3');

            // Display price only: money lives in the central billing system.
            $table->unsignedInteger('price_paise')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('packages');
    }
};

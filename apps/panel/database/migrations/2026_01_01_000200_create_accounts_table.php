<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The panel owns accounts; users own logins. A reseller owns accounts
        // without owning the machine, and an admin owns the machine.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reseller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('package_id')->nullable()->constrained()->nullOnDelete();

            $table->string('username', 16)->unique();   // the Linux user
            $table->string('domain', 253)->unique();
            $table->string('status', 16)->default('active'); // active|suspended|pending

            $table->unsignedInteger('disk_mb');
            $table->unsignedInteger('inodes');
            $table->unsignedInteger('bandwidth_mb');
            $table->string('php_version', 8)->default('8.3');

            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reseller_id', 'status']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->string('document_root', 512);
            $table->boolean('is_primary')->default(false);

            // none|pending|active|failed
            $table->string('ssl_status', 16)->default('none');
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
        Schema::dropIfExists('accounts');
    }
};

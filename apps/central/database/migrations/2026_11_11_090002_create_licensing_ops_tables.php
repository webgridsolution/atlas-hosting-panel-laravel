<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fingerprint_hash', 64)->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('panel_version', 20)->nullable();
            $table->string('status', 16)->default('active'); // active, deactivated
            $table->timestamp('activated_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index(['license_id', 'status']);
        });

        Schema::create('license_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->index(); // issued, activated, verified, renewed, suspended, revoked, transferred, transfer_refused
            $table->string('actor_type', 20)->default('system'); // system, staff, customer, panel
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['license_id', 'created_at']);
        });

        Schema::create('license_keys', function (Blueprint $table) {
            $table->id();
            $table->string('kid', 32)->unique();
            $table->text('public_key');       // base64 Ed25519
            $table->text('private_key_enc');  // encrypted at rest with APP_KEY
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('activation_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 64)->unique();
            $table->string('fingerprint_hash', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_nonces');
        Schema::dropIfExists('license_keys');
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('license_activations');
    }
};

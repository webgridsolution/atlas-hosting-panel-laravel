<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('gstin', 15)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->json('billing_address')->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name', 80);
            $table->string('tagline')->nullable();
            $table->string('edition', 40)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('interval', 20); // monthly, quarterly, half_yearly, annual, lifetime
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor'); // paise / cents — never floats
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'interval', 'currency']);
        });

        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('label', 90);
            $table->string('category', 40)->index();
            $table->string('type', 20)->default('limit'); // limit | boolean
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->integer('value')->default(0); // -1 = unlimited
            $table->timestamps();

            $table->unique(['plan_id', 'feature_key']);
            $table->foreign('feature_key')->references('key')->on('features')->cascadeOnDelete();
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('key', 24)->unique();
            $table->string('status', 24)->default('pending_activation')->index();
            $table->unsignedSmallInteger('activation_limit')->default(1);
            $table->string('fingerprint_hash', 64)->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(14);
            $table->timestamps();
        });

        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253);
            $table->string('ip_address', 45)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('panel_version', 20)->nullable();
            $table->string('fingerprint_hash', 64)->nullable()->unique();
            $table->string('status', 20)->default('pending')->index(); // pending, online, offline, decommissioned
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('customers');
    }
};

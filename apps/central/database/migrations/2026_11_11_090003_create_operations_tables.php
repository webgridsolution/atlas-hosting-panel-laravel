<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('number', 16)->unique();          // TKT-1042
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 200);
            $table->string('category', 40)->default('general');
            $table->string('priority', 12)->default('normal')->index(); // low, normal, high, urgent
            $table->string('status', 16)->default('open')->index();     // open, pending, resolved, closed
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 64)->index();
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 48)->index();
            $table->string('severity', 12)->default('medium')->index(); // low, medium, high, critical
            $table->string('ip_address', 45)->nullable();
            $table->string('actor', 160)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->string('group', 40)->default('general')->index();
            $table->string('type', 16)->default('string'); // string, boolean, integer, json, secret
            $table->string('label', 120);
            $table->timestamps();
        });

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('component', 20)->index();   // panel, agent, installer
            $table->string('version', 20);
            $table->string('channel', 12)->default('stable')->index(); // stable, beta
            $table->text('notes')->nullable();
            $table->boolean('is_security_release')->default(false);
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['component', 'version', 'channel']);
        });

        Schema::create('release_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained()->cascadeOnDelete();
            $table->string('os', 40);
            $table->string('arch', 16)->default('x86_64');
            $table->string('filename', 160);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->text('signature')->nullable();
            $table->string('url', 500);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
        });

        Schema::create('download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('artifact_id')->constrained('release_artifacts')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_logs');
        Schema::dropIfExists('release_artifacts');
        Schema::dropIfExists('releases');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};

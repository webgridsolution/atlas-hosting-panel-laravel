<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hosting_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 48)->unique();
            $table->string('db_user', 32);
            $table->unsignedInteger('size_mb')->nullable();
            $table->timestamps();
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('address', 320)->unique();
            $table->string('local_part', 64);
            $table->string('domain', 253);
            $table->unsignedInteger('quota_mb')->default(1024);
            $table->timestamps();
        });

        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('domain', 253)->unique();
            $table->string('status', 16)->default('active'); // active|pending
            $table->timestamps();
        });

        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            $table->string('name', 253);       // "@", "www", "mail"
            $table->string('type', 8);         // A, AAAA, CNAME, MX, TXT, NS, SRV, CAA
            $table->text('content');
            $table->unsignedInteger('ttl')->default(3600);
            $table->unsignedSmallInteger('priority')->nullable();
            $table->timestamps();

            $table->index(['dns_zone_id', 'type']);
        });

        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('schedule', 120);
            $table->string('command', 1000);
            $table->string('description', 120)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);              // account|database|mail
            $table->string('target', 120)->nullable();
            $table->string('archive', 512)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('status', 16)->default('running'); // running|complete|failed
            $table->text('detail')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });

        // A local mirror of the agent journal: the panel shows what it asked
        // for and why, even if the agent machine later catches fire.
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('op', 64);
            $table->string('args_hash', 64)->nullable();
            $table->string('status', 16); // ok|error|denied
            $table->text('detail')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['op', 'created_at']);
        });

        Schema::create('licences', function (Blueprint $table) {
            $table->id();
            $table->text('token')->nullable();
            $table->string('kid', 32)->nullable();
            $table->string('state', 16)->default('unlicensed'); // active|grace|read_only|unlicensed
            $table->json('features')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'licences', 'operations', 'backups', 'cron_jobs', 'dns_records',
            'dns_zones', 'mailboxes', 'hosting_databases',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

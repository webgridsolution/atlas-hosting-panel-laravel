<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hosting-panel feature set: everything the customer console needs beyond
 * the original accounts/sites/mail/DNS core.
 *
 * Written to be re-runnable. `domains` predates this file, so its extra columns
 * are added defensively with hasColumn(); every new table is created behind
 * hasTable(). That keeps `migrate` safe on an existing installation as well as
 * on a fresh one.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'api_tokens', 'bandwidth_days', 'database_users', 'database_user_hosting_database',
        'directory_protections', 'email_catchalls', 'email_filters', 'email_forwarders',
        'email_settings', 'error_pages', 'ftp_accounts', 'git_repositories',
        'installed_applications', 'ip_blocks', 'login_events', 'mailing_lists',
        'mime_types', 'php_settings', 'redirects', 'remote_mysql_hosts', 'ssl_certificates',
    ];

    public function up(): void
    {
        $this->extendDomains();
        $this->createTables();
    }

    private function extendDomains(): void
    {
        $wanted = [
            'type' => fn (Blueprint $t) => $t->string('type', 16)->default('primary'),   // primary|addon|subdomain|parked
            'parent_id' => fn (Blueprint $t) => $t->foreignId('parent_id')->nullable()->constrained('domains')->nullOnDelete(),
            'label' => fn (Blueprint $t) => $t->string('label', 63)->nullable(),
            'php_version' => fn (Blueprint $t) => $t->string('php_version', 8)->nullable(),
            'mail_enabled' => fn (Blueprint $t) => $t->boolean('mail_enabled')->default(false),
            'status' => fn (Blueprint $t) => $t->string('status', 16)->default('active'),
            'provisioned_at' => fn (Blueprint $t) => $t->timestamp('provisioned_at')->nullable(),
        ];

        foreach ($wanted as $column => $definition) {
            if (! Schema::hasColumn('domains', $column)) {
                Schema::table('domains', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            }
        }
    }

    private function createTables(): void
    {
        /* ---- Databases: privilege separation ------------------------------ */
        if (! Schema::hasTable('database_users')) {
            Schema::create('database_users', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('username', 32);
                $table->string('host', 120)->default('localhost');
                $table->string('privileges', 12)->default('ALL');   // ALL|READ|WRITE
                $table->string('status', 16)->default('active');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                // MySQL itself keys a grant on user@host.
                $table->unique(['username', 'host']);
                $table->index('account_id');
            });
        }

        if (! Schema::hasTable('database_user_hosting_database')) {
            Schema::create('database_user_hosting_database', function (Blueprint $table) {
                $table->id();
                $table->foreignId('database_user_id')->constrained('database_users')->cascadeOnDelete();
                $table->foreignId('hosting_database_id')->constrained('hosting_databases')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['database_user_id', 'hosting_database_id'], 'db_user_database_unique');
            });
        }

        if (! Schema::hasTable('remote_mysql_hosts')) {
            Schema::create('remote_mysql_hosts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('host', 190);
                $table->string('label', 120)->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'host']);
            });
        }

        /* ---- FTP ---------------------------------------------------------- */
        if (! Schema::hasTable('ftp_accounts')) {
            Schema::create('ftp_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
                $table->string('username', 32)->unique();
                $table->string('home_dir', 512);
                $table->unsignedInteger('quota_mb')->default(0);      // 0 = unlimited
                $table->boolean('read_only')->default(false);
                $table->string('status', 16)->default('active');
                $table->timestamp('last_login_at')->nullable();
                $table->string('last_login_ip', 45)->nullable();
                $table->timestamps();
            });
        }

        /* ---- SSL ---------------------------------------------------------- */
        if (! Schema::hasTable('ssl_certificates')) {
            Schema::create('ssl_certificates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
                $table->string('common_name', 253);
                $table->string('kind', 24)->default('letsencrypt');   // letsencrypt|custom|selfsigned
                $table->text('san')->nullable();
                $table->longText('cert_pem')->nullable();            // public material only
                $table->string('key_path', 512)->nullable();         // a path reference, never a key
                $table->string('issuer', 190)->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->index(['account_id', 'expires_at']);
            });
        }

        /* ---- Mail policy -------------------------------------------------- */
        if (! Schema::hasTable('email_forwarders')) {
            Schema::create('email_forwarders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('source', 64);                       // local part
                $table->text('destination');                        // comma-separated addresses
                $table->boolean('keep_copy')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['domain_id', 'source']);
            });
        }

        if (! Schema::hasTable('autoresponders')) {
            Schema::create('autoresponders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('mailbox', 64);
                $table->string('subject', 190);
                $table->text('body');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['domain_id', 'mailbox']);
            });
        }

        if (! Schema::hasTable('email_catchalls')) {
            Schema::create('email_catchalls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('destination', 190);
                $table->timestamps();
                $table->unique('domain_id');
            });
        }

        if (! Schema::hasTable('email_settings')) {
            Schema::create('email_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->decimal('spam_threshold', 4, 1)->default(5.0);
                $table->string('spam_action', 16)->default('mark');   // mark|discard|move
                $table->boolean('dkim_enabled')->default(true);
                $table->boolean('spf_enabled')->default(true);
                $table->boolean('greylisting')->default(false);
                $table->unsignedInteger('mailbox_quota_mb')->default(1024);
                $table->timestamps();
                $table->unique('domain_id');
            });
        }

        if (! Schema::hasTable('email_filters')) {
            Schema::create('email_filters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('mailbox', 190);
                $table->string('field', 32);                          // from,to,cc,subject,body,header,x-spam-flag
                $table->string('operator', 16);                       // contains,equals,matches,starts,ends
                $table->string('value', 255);
                $table->string('action', 16);                         // discard|redirect|keep|mark_spam|move
                $table->string('action_arg', 255)->nullable();
                $table->unsignedInteger('priority')->default(10);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['domain_id', 'mailbox', 'priority']);
            });
        }

        if (! Schema::hasTable('mailing_lists')) {
            Schema::create('mailing_lists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('name', 32);
                $table->string('title', 120)->nullable();
                $table->text('members')->nullable();                 // comma-separated addresses
                $table->unsignedInteger('subscribers')->default(0);
                $table->string('status', 16)->default('active');
                $table->timestamps();
                $table->unique(['domain_id', 'name']);
            });
        }

        /* ---- Web configuration -------------------------------------------- */
        if (! Schema::hasTable('mime_types')) {
            Schema::create('mime_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('extension', 16);
                $table->string('mime_type', 190);
                $table->timestamps();
                $table->unique(['account_id', 'extension']);
            });
        }

        if (! Schema::hasTable('error_pages')) {
            Schema::create('error_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->unsignedSmallInteger('status_code');
                $table->longText('body');
                $table->timestamps();
                $table->unique(['domain_id', 'status_code']);
            });
        }

        if (! Schema::hasTable('php_settings')) {
            Schema::create('php_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('key', 64);
                $table->string('value', 255);
                $table->timestamps();
                $table->unique(['domain_id', 'key']);
            });
        }

        /* ---- Characters, redirects, security ------------------------------ */
        if (! Schema::hasTable('redirects')) {
            Schema::create('redirects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->constrained()->cascadeOnDelete();
                $table->string('source_path', 255);
                $table->string('target_url', 512);
                $table->unsignedSmallInteger('status_code')->default(301);
                $table->unsignedBigInteger('hits')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['domain_id', 'source_path']);
            });
        }

        if (! Schema::hasTable('ip_blocks')) {
            Schema::create('ip_blocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('cidr', 64);
                $table->string('reason', 190)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'cidr']);
            });
        }

        if (! Schema::hasTable('directory_protections')) {
            Schema::create('directory_protections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->string('path', 512);
                $table->string('realm', 120);
                $table->string('username', 32);
                $table->string('status', 16)->default('active');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'path']);
            });
        }

        if (! Schema::hasTable('api_tokens')) {
            Schema::create('api_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('token_hash', 64)->unique();          // sha256 of the plaintext
                $table->string('prefix', 16);
                $table->json('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->index('user_id');
            });
        }

        /* ---- Applications and git ----------------------------------------- */
        if (! Schema::hasTable('installed_applications')) {
            Schema::create('installed_applications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
                $table->string('slug', 32);
                $table->string('path', 255)->default('');
                $table->string('version', 32)->nullable();
                $table->string('url', 512)->nullable();
                $table->string('admin_user', 64)->nullable();
                $table->string('db_name', 64)->nullable();
                $table->string('status', 16)->default('installed');
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();
                $table->index(['account_id', 'slug']);
            });
        }

        if (! Schema::hasTable('git_repositories')) {
            Schema::create('git_repositories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
                $table->string('path', 512);
                $table->string('remote', 512)->nullable();
                $table->string('branch', 120)->default('main');
                $table->string('status', 16)->default('active');
                $table->timestamp('last_deploy_at')->nullable();
                $table->timestamps();
            });
        }

        /* ---- Observability ------------------------------------------------ */
        if (! Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
                $table->string('area', 16)->default('panel');        // panel|whm|webmail|central
                $table->string('email', 190)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->boolean('successful')->default(false);
                $table->string('reason', 120)->nullable();
                $table->timestamps();
                $table->index(['email', 'created_at']);
                $table->index(['account_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('bandwidth_days')) {
            Schema::create('bandwidth_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained()->cascadeOnDelete();
                $table->date('day');
                $table->unsignedBigInteger('http_mb')->default(0);
                $table->unsignedBigInteger('mail_mb')->default(0);
                $table->unsignedBigInteger('ftp_mb')->default(0);
                $table->timestamps();
                $table->unique(['account_id', 'day']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }

        foreach (['type', 'parent_id', 'label', 'php_version', 'mail_enabled', 'status', 'provisioned_at'] as $column) {
            if (Schema::hasColumn('domains', $column)) {
                Schema::table('domains', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

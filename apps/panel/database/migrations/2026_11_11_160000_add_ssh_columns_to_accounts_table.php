<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shell access is a per-account fact the panel has to remember.
 *
 * The agent is the source of truth for whether a login actually works — this
 * pair of columns is the panel's mirror so a listing can show which accounts
 * have shell access without an agent call per row. If the two disagree, the
 * agent wins and the next SSH screen load corrects the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('accounts', 'ssh_enabled')) {
                $table->boolean('ssh_enabled')->default(false)->after('php_version');
            }

            if (! Schema::hasColumn('accounts', 'ssh_enabled_at')) {
                $table->timestamp('ssh_enabled_at')->nullable()->after('ssh_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            if (Schema::hasColumn('accounts', 'ssh_enabled_at')) {
                $table->dropColumn('ssh_enabled_at');
            }

            if (Schema::hasColumn('accounts', 'ssh_enabled')) {
                $table->dropColumn('ssh_enabled');
            }
        });
    }
};

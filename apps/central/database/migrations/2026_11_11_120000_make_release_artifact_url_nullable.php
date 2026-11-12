<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artifacts are identified by filename and served by this application (or by
 * whatever CDN `atlas.downloads.base` points at). Storing an absolute URL in
 * the database went stale the moment the site moved, and it forced the seeder
 * to invent a domain. The column stays for the case where a vendor really does
 * host a build somewhere else — it is now optional instead of mandatory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('release_artifacts', function (Blueprint $table) {
            $table->string('url', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('release_artifacts', function (Blueprint $table) {
            $table->string('url', 500)->nullable(false)->change();
        });
    }
};

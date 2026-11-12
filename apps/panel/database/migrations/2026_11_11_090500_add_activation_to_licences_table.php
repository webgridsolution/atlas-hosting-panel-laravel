<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            // The raw vendor key, encrypted by the model cast. Needed to call
            // the heartbeat endpoint without asking the operator to paste it
            // again after every expiry.
            $table->text('licence_key')->nullable()->after('token');
            // Which machine the vendor bound this licence to. Never trusted for
            // authorisation — the vendor's signed answer is — but shown so an
            // operator can see why a key was refused.
            $table->string('fingerprint', 64)->nullable()->after('licence_key');
        });
    }

    public function down(): void
    {
        Schema::table('licences', function (Blueprint $table) {
            $table->dropColumn(['licence_key', 'fingerprint']);
        });
    }
};

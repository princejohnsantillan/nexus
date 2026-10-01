<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            // What the owner uses this account for, shown to agents so they
            // can tell two accounts of the same service apart.
            $table->string('description', 500)->nullable()->after('name');
            // Who the connection is signed in as, when the server tells us.
            $table->string('account_identity')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['description', 'account_identity']);
        });
    }
};

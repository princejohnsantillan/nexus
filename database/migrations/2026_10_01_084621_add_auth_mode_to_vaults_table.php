<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vaults', function (Blueprint $table) {
            // How clients prove they may use the vault: token, signed_url or oauth.
            $table->string('auth_mode')->default('token')->after('description');
            // Bumped to revoke every signed URL issued so far.
            $table->unsignedInteger('signed_url_version')->default(1)->after('auth_mode');
        });

        // Which OAuth clients were registered for which vault. A client's
        // tokens only ever open the vault it registered for.
        Schema::create('vault_oauth_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vault_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('oauth_client_id')->unique()->constrained('oauth_clients')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::table('tool_call_logs', function (Blueprint $table) {
            // Which credential made the call, e.g. "Token: Claude Code", "Signed URL", "OAuth: Claude".
            $table->string('via')->nullable()->after('vault_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('tool_call_logs', function (Blueprint $table) {
            $table->dropColumn('via');
        });

        Schema::dropIfExists('vault_oauth_clients');

        Schema::table('vaults', function (Blueprint $table) {
            $table->dropColumn(['auth_mode', 'signed_url_version']);
        });
    }
};

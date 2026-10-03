<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users sign in through identities, so a user can have more than GitHub.
 * Every existing user gets their GitHub identity from the users table.
 *
 * The GitHub columns on users stay, no longer required, and GitHub sign-in
 * keeps them up to date, so code that still reads them, or this release
 * rolled back, keeps working. Nothing in Nexus reads them any more; a later
 * migration drops them.
 *
 * On SQLite, making the columns nullable rebuilds the users table, which
 * Laravel does with foreign keys off, so nothing cascades from it. That only
 * works outside a transaction (SQLite migrations run without one), and it
 * is done before the identities exist, so it can't touch them either.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('github_id')->nullable()->change();
            $table->string('github_login')->nullable()->change();
        });

        Schema::create('sign_in_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id');
            $table->string('login');
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });

        DB::table('sign_in_identities')->insertUsing(
            ['user_id', 'provider', 'provider_user_id', 'login', 'created_at', 'updated_at'],
            fn (Builder $query): Builder => $query
                ->from('users')
                ->select('id')
                ->selectRaw("'github'")
                ->selectRaw('cast(github_id as varchar(255))')
                ->addSelect(['github_login', 'created_at', 'updated_at'])
                ->whereNotNull('github_id')
                ->whereNotNull('github_login')
                ->orderBy('id'),
        );
    }

    /**
     * Reverse the migrations. The GitHub columns are required again, so this
     * fails once anyone has signed up without GitHub.
     */
    public function down(): void
    {
        Schema::dropIfExists('sign_in_identities');

        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('github_id')->nullable(false)->change();
            $table->string('github_login')->nullable(false)->change();
        });
    }
};

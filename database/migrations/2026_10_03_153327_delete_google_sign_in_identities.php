<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Google sign-in is gone, so delete the Google identities people added.
     * Their other ways to sign in, GitHub or an email address, stay.
     */
    public function up(): void
    {
        DB::table('sign_in_identities')->where('provider', 'google')->delete();
    }

    /**
     * The deleted identities can't come back, and nothing could sign in
     * with them anyway.
     */
    public function down(): void
    {
        //
    }
};

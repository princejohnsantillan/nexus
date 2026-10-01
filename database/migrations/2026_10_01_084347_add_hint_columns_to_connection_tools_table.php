<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connection_tools', function (Blueprint $table) {
            // As the server declared them; null when it didn't say.
            $table->boolean('idempotent')->nullable()->after('destructive');
            $table->boolean('open_world')->nullable()->after('idempotent');
        });

        DB::table('connection_tools')->orderBy('id')->each(function (object $tool): void {
            $annotations = json_decode($tool->definition, true)['annotations'] ?? [];

            DB::table('connection_tools')->where('id', $tool->id)->update([
                'idempotent' => is_bool($annotations['idempotentHint'] ?? null) ? $annotations['idempotentHint'] : null,
                'open_world' => is_bool($annotations['openWorldHint'] ?? null) ? $annotations['openWorldHint'] : null,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('connection_tools', function (Blueprint $table) {
            $table->dropColumn(['idempotent', 'open_world']);
        });
    }
};

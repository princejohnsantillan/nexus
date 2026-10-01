<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\StarAccessMode;
use App\Models\Star;
use Illuminate\Support\Facades\DB;

class ChangeStarAccessMode
{
    /**
     * Switch the Star to the access mode, retiring the credentials of the
     * mode it leaves for good, so none of them works again even if the Star
     * switches back later: leaving token mode revokes its tokens, and
     * leaving signed-URL mode rotates its signed URL. Nothing changes when
     * the Star already uses the mode.
     *
     * The Star's row is locked while it switches, and CreateStarToken locks
     * it too and creates tokens only in token mode, so a token created at
     * the same moment is either revoked with the others or refused.
     */
    public function handle(Star $star, StarAccessMode $accessMode): void
    {
        DB::transaction(function () use ($star, $accessMode): void {
            $current = Star::query()->whereKey($star->id)->lockForUpdate()->firstOrFail();

            if ($current->access_mode === $accessMode) {
                return;
            }

            match ($current->access_mode) {
                StarAccessMode::Token => $current->tokens()->delete(),
                StarAccessMode::SignedUrl => $current->signed_url_version++,
            };

            $current->access_mode = $accessMode;
            $current->save();
        });

        $star->refresh();
    }
}

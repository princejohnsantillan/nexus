<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Casts\AsEncryptedSecrets;
use App\Encryption\Secrets;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A model that holds encrypted secrets the way a Connection does, for testing
 * the AsEncryptedSecrets cast before any real model uses it.
 *
 * @property int $id
 * @property int|null $user_id
 * @property Secrets $secrets
 */
#[Fillable(['user_id'])]
#[Hidden(['secrets'])]
class SecretHolder extends Model
{
    public static function createTable(): void
    {
        Schema::create('secret_holders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('secrets')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secrets' => AsEncryptedSecrets::class,
        ];
    }
}

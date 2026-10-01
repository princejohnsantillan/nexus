<?php

declare(strict_types=1);

namespace App\Encryption;

use Illuminate\Contracts\Encryption\DecryptException;
use LogicException;
use SensitiveParameter;

/**
 * A model's secrets, as the AsEncryptedSecrets cast hands them out.
 *
 * Read one with get() or all of them with all(); change them with put(),
 * then save the model. They are decrypted on first read and re-encrypted
 * only when they, or their owner, change.
 */
final class Secrets
{
    /**
     * The decrypted secrets, once read.
     *
     * @var array<array-key, mixed>|null
     */
    private ?array $values = null;

    private bool $changed = false;

    public function __construct(
        private ?string $ciphertext = null,
        private ?int $ownerId = null,
    ) {}

    /**
     * @throws DecryptException when the secrets can no longer be decrypted
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws DecryptException when the secrets can no longer be decrypted
     */
    public function all(): array
    {
        return $this->values ??= $this->ciphertext === null ? [] : resolve(SecretCipher::class)->decrypt(
            $this->ownerId ?? throw new LogicException('These secrets have no owner to decrypt them for.'),
            $this->ciphertext,
        );
    }

    /**
     * Merge values into the secrets; a null value removes that secret.
     *
     * @param  array<array-key, mixed>  $secrets
     *
     * @throws DecryptException when the current secrets can no longer be decrypted
     */
    public function put(#[SensitiveParameter] array $secrets): self
    {
        $this->values = array_filter(
            array_replace($this->all(), $secrets),
            fn (mixed $value): bool => $value !== null,
        );

        $this->changed = true;

        return $this;
    }

    /**
     * The ciphertext to store for this owner: the stored one, unless the
     * secrets or their owner changed since it was read.
     *
     * @internal for the AsEncryptedSecrets cast
     */
    public function ciphertextFor(?int $ownerId): ?string
    {
        if (! $this->changed && $ownerId === $this->ownerId) {
            return $this->ciphertext;
        }

        $values = $this->all();

        $this->ciphertext = $values === [] ? null : resolve(SecretCipher::class)->encrypt(
            $ownerId ?? throw new LogicException('Set the owner (user_id) before saving secrets.'),
            $values,
        );
        $this->ownerId = $ownerId;
        $this->changed = false;

        return $this->ciphertext;
    }
}

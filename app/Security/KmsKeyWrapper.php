<?php

namespace App\Security;

use Aws\Kms\KmsClient;
use SensitiveParameter;

/**
 * Wraps data keys with an AWS KMS key. KMS logs every unwrap, and the
 * encryption context ties each wrapped key to its user.
 */
class KmsKeyWrapper implements KeyWrapper
{
    public function __construct(
        protected KmsClient $kms,
        protected string $keyId,
    ) {}

    public function name(): string
    {
        return 'kms';
    }

    public function wrap(#[SensitiveParameter] string $dataKey, int $userId): string
    {
        $result = $this->kms->encrypt([
            'KeyId' => $this->keyId,
            'Plaintext' => $dataKey,
            'EncryptionContext' => $this->context($userId),
        ]);

        return base64_encode((string) $result['CiphertextBlob']);
    }

    public function unwrap(string $wrappedKey, int $userId): string
    {
        $result = $this->kms->decrypt([
            'KeyId' => $this->keyId,
            'CiphertextBlob' => base64_decode($wrappedKey, true),
            'EncryptionContext' => $this->context($userId),
        ]);

        return (string) $result['Plaintext'];
    }

    /**
     * @return array<string, string>
     */
    protected function context(int $userId): array
    {
        return ['app' => 'nexus', 'user' => (string) $userId];
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Encryption\SecretCipher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

#[Signature('nexus:master-key')]
#[Description('Print a new master key for encrypting credentials (writes no files)')]
class GenerateMasterKeyCommand extends Command
{
    /**
     * Print the key on standard output, so it can be piped, and the advice on standard error.
     */
    public function handle(): int
    {
        $this->line('NEXUS_MASTER_KEY=base64:'.base64_encode(Encrypter::generateKey(SecretCipher::CIPHER)));

        $this->getOutput()->getErrorStyle()->writeln([
            '',
            'Add this line to the environment (.env locally, a secret on Laravel Cloud).',
            'Keep the key apart from APP_KEY and the database, and never change or lose it:',
            'without it, every stored credential is unreadable.',
        ]);

        return self::SUCCESS;
    }
}

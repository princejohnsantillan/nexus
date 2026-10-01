<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nexus:master-key')]
#[Description('Generate a master key for the local credential-encryption driver')]
class GenerateMasterKey extends Command
{
    public function handle(): int
    {
        $this->line('NEXUS_MASTER_KEY=base64:'.base64_encode(random_bytes(32)));
        $this->newLine();
        $this->components->warn('Store it outside the database and apart from APP_KEY. Losing it makes every stored credential unreadable.');

        return self::SUCCESS;
    }
}

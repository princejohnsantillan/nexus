<?php

use App\Http\Middleware\AuthenticateVaultToken;
use App\Mcp\Servers\VaultServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/{vault}', VaultServer::class)
    ->where('vault', '[0-9a-z]{26}')
    ->middleware([AuthenticateVaultToken::class, 'throttle:mcp'])
    ->name('mcp.vault');

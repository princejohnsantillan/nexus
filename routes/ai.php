<?php

use App\Http\Controllers\VaultOAuthController;
use App\Http\Middleware\AuthenticateVaultRequest;
use App\Mcp\Servers\VaultServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// Nexus writes its own WWW-Authenticate challenge, which differs per vault mode.
Mcp::web('/mcp/{vault}', VaultServer::class)
    ->where('vault', '[0-9a-z]{26}')
    ->middleware([AuthenticateVaultRequest::class, 'throttle:mcp'])
    ->withoutMiddleware(AddWwwAuthenticateHeader::class)
    ->name('mcp.vault');

// OAuth discovery and registration for OAuth-mode vaults. Each vault is its own issuer.
Route::get('/.well-known/oauth-protected-resource/mcp/{vault}', [VaultOAuthController::class, 'protectedResource'])
    ->where('vault', '[0-9a-z]{26}')
    ->name('mcp.oauth.protected-resource');

Route::get('/.well-known/oauth-authorization-server/oauth/vaults/{vault}', [VaultOAuthController::class, 'authorizationServer'])
    ->where('vault', '[0-9a-z]{26}')
    ->name('mcp.oauth.authorization-server');

Route::get('/.well-known/openid-configuration/oauth/vaults/{vault}', [VaultOAuthController::class, 'authorizationServer'])
    ->where('vault', '[0-9a-z]{26}');

Route::post('/oauth/vaults/{vault}/register', [VaultOAuthController::class, 'register'])
    ->where('vault', '[0-9a-z]{26}')
    ->middleware('throttle:oauth-registration')
    ->name('mcp.oauth.register');

<?php

declare(strict_types=1);

use App\Http\Controllers\StarOAuth\AuthorizationServerMetadataController;
use App\Http\Controllers\StarOAuth\ProtectedResourceMetadataController;
use App\Http\Controllers\StarOAuth\RegisterClientController;
use App\Http\Middleware\AuthenticateStarRequest;
use App\Mcp\Servers\StarServer;
use App\Models\Star;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Laravel\Passport\Http\Controllers\AccessTokenController;

/*
|--------------------------------------------------------------------------
| Stars' MCP endpoints
|--------------------------------------------------------------------------
|
| Clients reach a Star at its public id. These routes have no session and
| no route-model binding: the access middleware looks the Star up and
| authenticates the client as the Star's access mode asks, answering
| failures with its own WWW-Authenticate challenge, so laravel/mcp's
| generic one is left off. Calls are then rate limited per credential.
|
*/

Mcp::web('/mcp/{star}', StarServer::class)
    ->where('star', '[a-z0-9]{'.Star::PUBLIC_ID_LENGTH.'}')
    ->middleware([AuthenticateStarRequest::class, 'throttle:mcp'])
    ->withoutMiddleware(AddWwwAuthenticateHeader::class)
    ->name('mcp.star');

/*
|--------------------------------------------------------------------------
| OAuth to Nexus
|--------------------------------------------------------------------------
|
| A Star in OAuth mode is its own authorization server, issuer
| `{APP_URL}/oauth/stars/{star}`: its 401 points clients to its protected
| resource metadata, which names that issuer, whose metadata names the
| Star's own registration endpoint. Any other Star has none of them (404).
| Clients register, are sent to Passport's authorization endpoint (in
| routes/web.php, which has the session) and then exchange the code at
| Passport's token endpoint, which every Star shares. These need no
| session, CSRF token or route-model binding.
|
*/

$publicId = '[a-z0-9]{'.Star::PUBLIC_ID_LENGTH.'}';

Route::get('/.well-known/oauth-protected-resource/mcp/{star}', ProtectedResourceMetadataController::class)
    ->where('star', $publicId)
    ->name('mcp.oauth.protected-resource');

Route::get('/.well-known/oauth-authorization-server/oauth/stars/{star}', AuthorizationServerMetadataController::class)
    ->where('star', $publicId)
    ->name('mcp.oauth.authorization-server');

Route::get('/.well-known/openid-configuration/oauth/stars/{star}', AuthorizationServerMetadataController::class)
    ->where('star', $publicId)
    ->name('mcp.oauth.openid-configuration');

Route::post('/oauth/stars/{star}/register', RegisterClientController::class)
    ->where('star', $publicId)
    ->middleware('throttle:mcp-registration')
    ->name('mcp.oauth.register');

Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware('throttle')
    ->name('passport.token');

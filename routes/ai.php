<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateStarRequest;
use App\Mcp\Servers\StarServer;
use App\Models\Star;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

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

<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\PayMongoWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Services that tell Nexus about something, server to server. These routes
| are outside the web middleware group, so they have no session, cookies
| or CSRF token, and need no signed-in user: each one authenticates its
| sender itself, such as by a signature, and is rate limited per IP address.
|
*/

Route::post('/webhooks/paymongo', PayMongoWebhookController::class)
    ->middleware('throttle:paymongo-webhooks')
    ->name('webhooks.paymongo');

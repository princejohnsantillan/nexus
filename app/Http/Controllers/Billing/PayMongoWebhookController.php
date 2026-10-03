<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\ReceivePayMongoWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PayMongo's webhook, `POST /webhooks/paymongo` (ReceivePayMongoWebhook).
 *
 * PayMongo counts a delivery as received when it gets a 200–209 with a JSON
 * body within 30 seconds, and tries again otherwise, so a delivery that is
 * PayMongo's gets a 200 at once, its work queued, whatever it was about. One
 * that isn't gets a 403 that doesn't say why.
 */
class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, ReceivePayMongoWebhook $receive): JsonResponse
    {
        $received = $receive->handle($request->getContent(), $request->header('Paymongo-Signature'));

        return response()->json(['received' => $received], $received ? 200 : 403);
    }
}

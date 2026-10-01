<?php

declare(strict_types=1);

namespace App\Http\Controllers\StarOAuth;

use App\Http\Controllers\Controller;
use App\Models\StarOAuthClient;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Http\Controllers\HandlesOAuthErrors;
use Laravel\Passport\Http\Controllers\RetrievesAuthRequestFromSession;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approves the authorization request on the consent screen, as Passport's
 * own approval does, but only for the owner of the Star the client
 * registered with, while that Star uses OAuth. The consent screen offers
 * no Approve button otherwise; this refuses an approval sent anyway, so
 * nobody can approve a client for someone else's Star. The approval is
 * noted on the client's binding, which makes it one of the Star's
 * connected apps.
 */
class ApproveAuthorizationController extends Controller
{
    use ConvertsPsrResponses, HandlesOAuthErrors, RetrievesAuthRequestFromSession;

    public function __construct(private readonly AuthorizationServer $server) {}

    public function __invoke(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);
        $user = $request->user();
        $app = $user instanceof User ? StarOAuthClient::approvableBy($user, $authRequest->getClient()->getIdentifier()) : null;

        abort_unless($app instanceof StarOAuthClient, 403, __('Only the owner of a Star that uses OAuth can approve apps for it.'));

        $authRequest->setAuthorizationApproved(true);

        $response = $this->withErrorHandling(fn (): Response => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse),
        ));

        $app->markApproved();

        return $response;
    }
}

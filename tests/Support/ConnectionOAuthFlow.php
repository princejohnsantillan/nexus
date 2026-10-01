<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Connection;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Drives a Connection's OAuth sign-in through Nexus's own routes, as the
 * signed-in owner's browser would.
 */
final class ConnectionOAuthFlow
{
    /**
     * Start signing the Connection in: the server's sign-in page Nexus sends
     * the browser to.
     */
    public static function start(TestCase $test, Connection $connection): string
    {
        $location = $test->get(route('connections.connect', $connection))->assertRedirect()->headers->get('Location');

        return (string) $location;
    }

    /**
     * Sign the Connection in end to end: start, approve Nexus on the
     * authorization server, and come back to the callback.
     *
     * @return TestResponse<Response>
     */
    public static function signIn(TestCase $test, Connection $connection, FakeAuthorizationServer $authorizationServer): TestResponse
    {
        return $test->get($authorizationServer->approve(self::start($test, $connection)));
    }
}

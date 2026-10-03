<?php

declare(strict_types=1);

use App\Jobs\RefreshCatalogInBackground;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): string => route('auth.sign-in'));
        $middleware->redirectUsersTo(fn (): string => route('stars.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReportWhen(RefreshCatalogInBackground::isGivenUp(...));

        // Passport's guard reports every access token it refuses, and MCP clients send expired ones hourly.
        $exceptions->dontReportWhen(
            fn (Throwable $exception): bool => $exception instanceof OAuthServerException && $exception->getHttpStatusCode() < 500,
        );
    })->create();

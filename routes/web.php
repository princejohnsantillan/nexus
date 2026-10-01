<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\DevSignInController;
use App\Http\Controllers\Auth\GitHubCallbackController;
use App\Http\Controllers\Auth\GitHubRedirectController;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\ConnectionOAuth\ClientMetadataDocumentController;
use App\Http\Controllers\ConnectionOAuth\SignInCallbackController;
use App\Http\Controllers\ConnectionOAuth\StartSignInController;
use App\Http\Controllers\StarOAuth\ApproveAuthorizationController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::middleware('guest')->group(function (): void {
    Route::livewire('/', 'pages::welcome')->name('home');

    Route::get('/auth/github', GitHubRedirectController::class)->name('auth.github');
    Route::get('/auth/github/callback', GitHubCallbackController::class)->name('auth.github.callback');
});

Route::get('/dev/sign-in/{account}', DevSignInController::class)->name('dev.sign-in');

Route::get('/oauth/client-metadata.json', ClientMetadataDocumentController::class)->name('oauth.client-metadata');

// OAuth to Nexus: Passport's consent screen, for Stars in OAuth mode. Guests are signed in first.
Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');

Route::middleware('auth')->group(function (): void {
    Route::livewire('/stars', 'pages::stars.index')->name('stars.index');
    Route::livewire('/stars/{star}', 'pages::stars.show')->name('stars.show');
    Route::livewire('/stars/{star}/tools', 'pages::stars.tools')->name('stars.tools');
    Route::livewire('/stars/{star}/access', 'pages::stars.access')->name('stars.access');
    Route::livewire('/connections', 'pages::connections.index')->name('connections.index');
    Route::livewire('/connections/add', 'pages::connections.add')->name('connections.add');
    Route::livewire('/connections/add/custom', 'pages::connections.add-custom')->name('connections.add-custom');
    Route::livewire('/connections/{connection}', 'pages::connections.show')->name('connections.show');
    Route::livewire('/connections/{connection}/tools', 'pages::connections.tools')->name('connections.tools');
    Route::get('/connections/{connection}/connect', StartSignInController::class)->name('connections.connect');
    Route::get('/oauth/callback', SignInCallbackController::class)->name('oauth.callback');
    Route::livewire('/activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('/settings', 'pages::settings.index')->name('settings.index');

    Route::post('/oauth/authorize', ApproveAuthorizationController::class)->name('passport.authorizations.approve');
    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');

    Route::post('/logout', SignOutController::class)->name('logout');
});

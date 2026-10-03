<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AddGoogleController;
use App\Http\Controllers\Auth\DevSignInController;
use App\Http\Controllers\Auth\GitHubCallbackController;
use App\Http\Controllers\Auth\GitHubRedirectController;
use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\SignOutController;
use App\Http\Controllers\ConnectionOAuth\ClientMetadataDocumentController;
use App\Http\Controllers\ConnectionOAuth\SignInCallbackController;
use App\Http\Controllers\ConnectionOAuth\StartSignInController;
use App\Http\Controllers\StarOAuth\ApproveAuthorizationController;
use App\Http\Controllers\StarOAuth\SwitchAccountController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

Route::middleware('guest')->group(function (): void {
    Route::livewire('/', 'pages::welcome')->name('home');
    Route::livewire('/sign-in', 'pages::auth.sign-in')->name('auth.sign-in');
    Route::livewire('/sign-in/code', 'pages::auth.email-code')->name('auth.email-code');

    Route::get('/auth/github', GitHubRedirectController::class)->name('auth.github');
    Route::get('/auth/github/callback', GitHubCallbackController::class)->name('auth.github.callback');
    Route::get('/auth/google', GoogleRedirectController::class)->name('auth.google');
});

// The Pricing page, for guests and signed-in users alike.
Route::livewire('/pricing', 'pages::pricing')->name('pricing');

// The legal pages, for guests and signed-in users alike.
Route::livewire('/terms', 'pages::legal.terms')->name('legal.terms');
Route::livewire('/privacy', 'pages::legal.privacy')->name('legal.privacy');
Route::livewire('/refunds', 'pages::legal.refunds')->name('legal.refunds');

// Google's one callback, for guests signing in and for signed-in users adding Google from Settings.
Route::get('/auth/google/callback', GoogleCallbackController::class)->name('auth.google.callback');

Route::get('/dev/sign-in/{account}', DevSignInController::class)->name('dev.sign-in');

Route::get('/oauth/client-metadata.json', ClientMetadataDocumentController::class)->name('oauth.client-metadata');

// OAuth to Nexus: Passport's consent screen, for Stars in OAuth mode. Guests are signed in first.
Route::get('/oauth/authorize', [AuthorizationController::class, 'authorize'])->name('passport.authorizations.authorize');

Route::middleware('auth')->group(function (): void {
    Route::livewire('/stars', 'pages::stars.index')->name('stars.index');
    Route::livewire('/stars/{star}', 'pages::stars.show')->name('stars.show');
    Route::livewire('/stars/{star}/tools', 'pages::stars.tools')->name('stars.tools');
    Route::livewire('/stars/{star}/prompts', 'pages::stars.prompts')->name('stars.prompts');
    Route::livewire('/stars/{star}/access', 'pages::stars.access')->name('stars.access');
    Route::livewire('/connections', 'pages::connections.index')->name('connections.index');
    Route::redirect('/connections/add', '/connections#add-more')->name('connections.add');
    Route::livewire('/connections/add/custom', 'pages::connections.add-custom')->name('connections.add-custom');
    Route::livewire('/connections/{connection}', 'pages::connections.show')->name('connections.show');
    Route::livewire('/connections/{connection}/tools', 'pages::connections.tools')->name('connections.tools');
    Route::livewire('/connections/{connection}/prompts', 'pages::connections.prompts')->name('connections.prompts');
    Route::get('/connections/{connection}/connect', StartSignInController::class)->name('connections.connect');
    Route::get('/oauth/callback', SignInCallbackController::class)->name('oauth.callback');
    Route::livewire('/activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('/billing', 'pages::billing.index')->name('billing.index');
    Route::livewire('/billing/upgrade', 'pages::billing.upgrade')->name('billing.upgrade');
    Route::livewire('/settings', 'pages::settings.index')->name('settings.index');
    Route::get('/settings/sign-in-methods/google', AddGoogleController::class)->name('settings.add-google');

    Route::post('/oauth/authorize', ApproveAuthorizationController::class)->name('passport.authorizations.approve');
    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
    Route::post('/oauth/authorize/switch-account', SwitchAccountController::class)->name('oauth.switch-account');

    Route::post('/logout', SignOutController::class)->name('logout');
});

<?php

use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\ConnectionOAuthController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::view('/login', 'welcome')->name('login');
    Route::get('/auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('auth.redirect');
    Route::get('/auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('auth.callback');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/connections/{connection}/oauth', [ConnectionOAuthController::class, 'connect'])->name('connections.oauth.connect');
    Route::get('/oauth/callback', [ConnectionOAuthController::class, 'callback'])->name('oauth.callback');
});

// Nexus's Client ID Metadata Document, for servers that accept CIMD instead of registration.
Route::get('/oauth/client-metadata.json', [ConnectionOAuthController::class, 'clientMetadata'])->name('oauth.client-metadata');

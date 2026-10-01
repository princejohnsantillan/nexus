<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\DevSignInController;
use App\Http\Controllers\Auth\GitHubCallbackController;
use App\Http\Controllers\Auth\GitHubRedirectController;
use App\Http\Controllers\Auth\SignOutController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::livewire('/', 'pages::welcome')->name('home');

    Route::get('/auth/github', GitHubRedirectController::class)->name('auth.github');
    Route::get('/auth/github/callback', GitHubCallbackController::class)->name('auth.github.callback');
});

Route::get('/dev/sign-in/{account}', DevSignInController::class)->name('dev.sign-in');

Route::middleware('auth')->group(function (): void {
    Route::livewire('/stars', 'pages::stars.index')->name('stars.index');
    Route::livewire('/connections', 'pages::connections.index')->name('connections.index');
    Route::livewire('/activity', 'pages::activity.index')->name('activity.index');
    Route::livewire('/settings', 'pages::settings.index')->name('settings.index');

    Route::post('/logout', SignOutController::class)->name('logout');
});

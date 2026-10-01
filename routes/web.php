<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::welcome')->name('home');

Route::livewire('/stars', 'pages::stars.index')->name('stars.index');
Route::livewire('/connections', 'pages::connections.index')->name('connections.index');
Route::livewire('/activity', 'pages::activity.index')->name('activity.index');

<?php

declare(strict_types=1);

use Livewire\Livewire;

it('shows an empty state when there are no Stars', function (): void {
    Livewire::test('pages::stars.index')
        ->assertOk()
        ->assertSeeText('No Stars yet')
        ->assertSeeText('Create a Star, choose the Connections it includes');
});

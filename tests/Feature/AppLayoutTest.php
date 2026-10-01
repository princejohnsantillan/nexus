<?php

declare(strict_types=1);

dataset('app pages', [
    'stars' => ['stars.index', 'Stars'],
    'connections' => ['connections.index', 'Connections'],
    'activity' => ['activity.index', 'Activity'],
]);

it('renders each app page inside the sidebar layout', function (string $route, string $title): void {
    $this->get(route($route))
        ->assertOk()
        ->assertSee("<title>{$title} · Nexus</title>", escape: false)
        ->assertSee('data-flux-sidebar', escape: false)
        ->assertSee(route('stars.index'))
        ->assertSee(route('connections.index'))
        ->assertSee(route('activity.index'));
})->with('app pages');

it('marks the current page in the sidebar', function (string $route, string $title): void {
    $html = $this->get(route($route))->assertOk()->getContent();

    expect($html)->toMatch('/<a[^>]*href="'.preg_quote(route($route), '/').'"[^>]*data-current/');
})->with('app pages');

it('offers the appearance switch in the profile menu', function (): void {
    $this->get(route('stars.index'))
        ->assertOk()
        ->assertSee('x-model="$flux.appearance"', escape: false)
        ->assertSeeTextInOrder(['Appearance', 'Light', 'Dark', 'System']);
});

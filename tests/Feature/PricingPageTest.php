<?php

declare(strict_types=1);

use App\Models\SignInIdentity;
use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Tests\Support\SentEmailCodes;

/**
 * Where the element the selector picks on the rendered page links to.
 */
function pricingPageHref(string $html, string $selector): ?string
{
    return HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelector($selector)?->getAttribute('href');
}

beforeEach(function (): void {
    config([
        'nexus.plans.free' => ['stars' => 2, 'connections' => 10, 'tool_calls_per_week' => 3000],
        'nexus.plans.pro.prices' => ['month' => 49900, 'year' => 499900],
    ]);
});

it('shows a guest Free and Pro yearly first, with a way to sign in', function (): void {
    $response = $this->get(route('pricing'));

    $response->assertSeeHtml('<title>Pricing · Nexus</title>')
        ->assertSeeTextInOrder(['Pricing in pesos', 'Start free. Go Pro when', 'two Stars aren\'t enough.', 'Every plan gets every connector and every AI client.'])
        ->assertSeeTextInOrder(['Monthly', 'Yearly', 'Save ₱989'])
        ->assertSeeTextInOrder(['Free', '₱0', 'forever', 'For trying Nexus with a couple of clients.', '2 Stars', '10 Connections', '3,000 tool calls a week', 'Start free'])
        ->assertSeeTextInOrder(['Pro', 'Billed yearly', '₱4,999', '/ year', 'About ₱417 a month. ₱499 if you pay monthly.', 'Unlimited Stars', 'Unlimited Connections', 'Unlimited tool calls', 'Start with Pro'])
        ->assertSeeTextInOrder([
            'How do I pay?', 'On PayMongo\'s secure checkout, with a card, GCash, Maya or QR Ph.',
            'What happens when Pro ends?', 'You\'re back on Free.',
            'What counts as a tool call?', 'Free includes 3,000 a week, reset every Monday; Pro has no weekly limit.',
            'Can I get a refund?', 'Within 7 days of a payment, if you\'ve changed your mind.', 'See the refund policy.',
        ])
        ->assertSeeText('Nexus is open source. Prices are in Philippine pesos.')
        ->assertSee('href="'.route('auth.sign-in').'"', escape: false)
        ->assertDontSeeText('Go to Stars')
        ->assertDontSee('data-flux-sidebar', escape: false);
    expect(pricingPageHref($response->getContent() ?: '', '[data-refund-policy-link]'))->toBe(route('legal.refunds'));
});

it('shows a signed-in user the page with a way back to their Stars', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('pricing'))
        ->assertSeeText(['Start free. Go Pro when', 'Go to Stars'])
        ->assertSee('href="'.route('stars.index').'"', escape: false)
        ->assertDontSee('href="'.route('auth.sign-in').'"', escape: false);
});

it('marks Pricing as the current page in the header, also after the period changes', function (): void {
    $page = Livewire::test('pages::pricing');

    expect(HTMLDocument::createFromString($page->html(), LIBXML_NOERROR)->querySelector('header [aria-current="page"]')?->textContent)->toBe('Pricing');

    $page->set('period', 'month');

    expect(HTMLDocument::createFromString($page->html(), LIBXML_NOERROR)->querySelector('header [aria-current="page"]')?->textContent)->toBe('Pricing');
});

it('switches Pro between the yearly and monthly prices from the plan config', function (): void {
    config([
        'nexus.plans.free' => ['stars' => 3, 'connections' => 25, 'tool_calls_per_week' => 5000],
        'nexus.plans.pro.prices' => ['month' => 59900, 'year' => 599900],
    ]);

    // After an update Livewire's text assertions read the JSON response, so these read the rendered HTML.
    Livewire::test('pages::pricing')
        ->assertSet('period', 'year')
        ->assertSeeTextInOrder(['Save ₱1,189', '3 Stars', '25 Connections', '5,000 tool calls a week', 'Billed yearly', '₱5,999', '/ year', 'About ₱500 a month. ₱599 if you pay monthly.', 'Free includes 5,000 a week'])
        ->set('period', 'month')
        ->assertSeeHtmlInOrder(['Billed monthly', '₱599', '/ month', '₱7,188 a year if you pay monthly. Yearly saves ₱1,189.'])
        ->assertDontSeeHtml('₱5,999')
        ->set('period', 'year')
        ->assertSeeHtmlInOrder(['Billed yearly', '₱5,999', '/ year'])
        ->assertDontSeeHtml('Billed monthly');
});

it('starts a guest free at sign-in and a signed-in user at their Stars', function (bool $signedIn, string $route): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $html = $this->get(route('pricing'))->getContent() ?: '';

    expect(pricingPageHref($html, '[data-start-free]'))->toBe(route($route));
})->with([
    'a guest' => [false, 'auth.sign-in'],
    'a signed-in user' => [true, 'stars.index'],
]);

it('starts Pro on the Upgrade page with the period picked', function (): void {
    $this->actingAs(User::factory()->create());

    $page = Livewire::test('pages::pricing');

    expect(pricingPageHref($page->html(), '[data-start-pro]'))->toBe(route('billing.upgrade', ['period' => 'year']))
        ->and(pricingPageHref($page->set('period', 'month')->html(), '[data-start-pro]'))->toBe(route('billing.upgrade', ['period' => 'month']));
});

it('prices Pro yearly when the picker sends something else', function (): void {
    $page = Livewire::test('pages::pricing')->set('period', 'decade');

    $page->assertSeeHtmlInOrder(['Billed yearly', '₱4,999', '/ year']);
    expect(pricingPageHref($page->html(), '[data-start-pro]'))->toBe(route('billing.upgrade', ['period' => 'year']));
});

it('brings a guest who starts Pro monthly back to the Upgrade page after signing in with GitHub', function (): void {
    $startWithPro = (string) pricingPageHref(Livewire::test('pages::pricing')->set('period', 'month')->html(), '[data-start-pro]');
    Socialite::fake('github', SocialiteUser::fake(['id' => 583231, 'nickname' => 'octocat', 'name' => 'Mona Lisa Octocat', 'email' => 'octocat@github.com']));

    $this->get($startWithPro)->assertRedirect(route('auth.sign-in'));
    $this->get(route('auth.github.callback'))->assertRedirect(route('billing.upgrade', ['period' => 'month']));

    $this->get(route('billing.upgrade', ['period' => 'month']))->assertSeeTextInOrder(['Billed monthly', '₱499', '/ month']);
});

it('brings a guest who starts Pro monthly back to the Upgrade page after signing in with an email code', function (): void {
    Notification::fake();
    User::factory()->has(SignInIdentity::factory()->email('ada@example.com'), 'signInIdentities')->create();
    $startWithPro = (string) pricingPageHref(Livewire::test('pages::pricing')->set('period', 'month')->html(), '[data-start-pro]');

    $this->get($startWithPro)->assertRedirect(route('auth.sign-in'));
    Livewire::test('pages::auth.sign-in')->set('email', 'ada@example.com')->call('sendCode')->assertRedirect(route('auth.email-code'));

    Livewire::test('pages::auth.email-code')
        ->set('code', SentEmailCodes::latest('ada@example.com'))
        ->call('signIn')
        ->assertRedirect(route('billing.upgrade', ['period' => 'month']));
});

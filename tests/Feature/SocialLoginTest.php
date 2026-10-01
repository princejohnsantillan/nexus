<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_first_sign_in_creates_the_account(): void
    {
        Socialite::fake('github', SocialiteUser::fake(['id' => 'gh-1', 'email' => 'Ada@Example.com', 'name' => 'Ada']));

        $this->get(route('auth.callback', 'github'))->assertRedirect('/app');

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertNull($user->password);
        $this->assertSame('gh-1', $user->socialAccounts()->sole()->provider_user_id);
    }

    public function test_signing_in_with_another_provider_lands_in_the_same_account(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);
        SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'github', 'provider_user_id' => 'gh-1']);

        Socialite::fake('google', SocialiteUser::fake(['id' => 'g-1', 'email' => 'ada@example.com', 'email_verified' => true]));

        $this->get(route('auth.callback', 'google'))->assertRedirect('/app');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(['github', 'google'], $user->socialAccounts()->orderBy('provider')->pluck('provider')->all());
    }

    public function test_an_unverified_google_email_cannot_take_over_an_account(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        Socialite::fake('google', SocialiteUser::fake(['id' => 'g-evil', 'email' => 'ada@example.com', 'email_verified' => false]));

        $this->get(route('auth.callback', 'google'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, SocialAccount::query()->count());
    }

    public function test_unsupported_providers_are_not_found(): void
    {
        $this->get(route('auth.redirect', 'facebook'))->assertNotFound();
    }

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/app')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Continue with GitHub');
    }
}

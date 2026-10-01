{{--
    OAuth to Nexus: the consent screen Passport shows when an MCP client
    asks to use a Star in OAuth mode. It names the client, the Star and the
    signed-in user, and offers Approve only when `$app` is set: the client
    registered with one of the user's own Stars, which still uses OAuth.
    Anyone else's Star isn't named. The forms post to Passport's approve
    and deny routes with the auth token of this request.
--}}
@php
    /** @var \Laravel\Passport\Client $client */
    /** @var \App\Models\User $user */
    /** @var \App\Models\StarOAuthClient|null $app */
    /** @var int $toolCount */
    /** @var string $authToken */
@endphp

<x-layouts::public>
    <x-slot:title>{{ __('Approve access') }}</x-slot:title>

    <div class="flex min-h-screen flex-col">
        <header class="mx-auto flex w-full max-w-5xl items-center px-6 py-4">
            <x-app-logo :href="route('stars.index')" />
        </header>

        <main class="mx-auto flex w-full max-w-lg flex-1 flex-col justify-center px-6 py-12">
            <flux:card class="space-y-6">
                @if ($app !== null)
                    <div>
                        <flux:heading size="lg" level="1">{{ __('Allow :client to use your Star “:star”?', ['client' => $client->name, 'star' => $app->star->name]) }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ trans_choice('It will be able to call the :count tool switched on in this Star, as you, until you revoke it on the Star\'s Access page.|It will be able to call the :count tools switched on in this Star, as you, until you revoke it on the Star\'s Access page.', $toolCount, ['count' => $toolCount]) }}
                        </flux:text>
                    </div>

                    <dl class="divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                            <dt><flux:text>{{ __('App') }}</flux:text></dt>
                            <dd class="break-all sm:col-span-2"><flux:text variant="strong">{{ $client->name }}</flux:text></dd>
                        </div>

                        <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                            <dt><flux:text>{{ __('Returns to') }}</flux:text></dt>
                            <dd class="break-all sm:col-span-2"><flux:text variant="strong">{{ implode(', ', $app->redirectHosts()) }}</flux:text></dd>
                        </div>

                        <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                            <dt><flux:text>{{ __('Star') }}</flux:text></dt>
                            <dd class="break-all sm:col-span-2"><flux:text variant="strong">{{ $app->star->name }}</flux:text></dd>
                        </div>

                        <div class="grid gap-1 py-3 sm:grid-cols-3 sm:gap-4">
                            <dt><flux:text>{{ __('Signed in as') }}</flux:text></dt>
                            <dd class="break-all sm:col-span-2"><flux:text variant="strong">{{ $user->name }} ({{ '@'.$user->github_login }})</flux:text></dd>
                        </div>
                    </dl>

                    <flux:text size="sm">{{ __('Only approve it if you just added this Star to :client yourself.', ['client' => $client->name]) }}</flux:text>
                @else
                    <div>
                        <flux:heading size="lg" level="1">{{ __(':client isn\'t asking for one of your Stars', ['client' => $client->name]) }}</flux:heading>
                        <flux:text class="mt-2">{{ __('You\'re signed in as :name (:login).', ['name' => $user->name, 'login' => '@'.$user->github_login]) }}</flux:text>
                    </div>

                    <flux:callout icon="exclamation-triangle" color="amber">
                        <flux:callout.text>{{ __('It registered with a Star that isn\'t yours, or that no longer lets clients sign in with OAuth. Approving it wouldn\'t give it access, so you can only deny it.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <div class="flex flex-wrap justify-end gap-2">
                    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">

                        <flux:button type="submit" variant="ghost">{{ __('Deny') }}</flux:button>
                    </form>

                    @if ($app !== null)
                        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                            @csrf
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">

                            <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                        </form>
                    @endif
                </div>
            </flux:card>
        </main>
    </div>
</x-layouts::public>

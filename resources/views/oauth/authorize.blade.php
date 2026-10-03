{{--
    OAuth to Nexus: the consent screen Passport shows when an MCP client
    asks to use a Star in OAuth mode. Nexus and the client side by side, the
    question, what the client could do as icon rows, where approving sends
    the user and who is signed in, with "Not you?" to sign in as someone
    else and come back to this screen. It offers Approve only when `$app` is
    set: the client registered with one of the user's own Stars, which still
    uses OAuth. Anyone else's Star isn't named. The forms post to Passport's
    approve and deny routes with the auth token of this request.
--}}
@php
    /** @var \Laravel\Passport\Client $client */
    /** @var \App\Models\User $user */
    /** @var \App\Models\StarOAuthClient|null $app */
    /** @var int $toolCount */
    /** @var string $authToken */
    /** @var string $switchAccountUrl */
@endphp

<x-layouts::public>
    <x-slot:title>{{ __('Approve access') }}</x-slot:title>

    <div class="flex min-h-screen flex-col">
        <header class="mx-auto flex w-full max-w-5xl items-center px-6 py-4">
            <x-app-logo :href="route('stars.index')" />
        </header>

        <main class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-4 py-12 sm:px-6">
            <div class="flex items-center justify-center" aria-hidden="true">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-950 text-white dark:bg-white dark:text-zinc-950">
                    <x-app-logo-icon class="size-6" />
                </div>

                <div class="w-8 border-t border-dashed border-zinc-300 sm:w-12 dark:border-white/20"></div>

                <div @class([
                    'flex size-7 shrink-0 items-center justify-center rounded-full',
                    'bg-accent-wash text-accent-content' => $app !== null,
                    'bg-warning-wash text-warning' => $app === null,
                ])>
                    @if ($app !== null)
                        <flux:icon.arrows-right-left variant="micro" />
                    @else
                        <flux:icon.exclamation-triangle variant="micro" />
                    @endif
                </div>

                <div class="w-8 border-t border-dashed border-zinc-300 sm:w-12 dark:border-white/20"></div>

                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-zinc-200 bg-white text-lg font-semibold text-zinc-950 dark:border-white/10 dark:bg-white/5 dark:text-white">
                    {{ Str::upper(Str::substr(trim($client->name), 0, 1)) }}
                </div>
            </div>

            <div class="mt-6 text-center">
                <flux:heading size="xl" level="1" class="text-balance">
                    @if ($app !== null)
                        {{ __('Allow :client to use your Star “:star”?', ['client' => $client->name, 'star' => $app->star->name]) }}
                    @else
                        {{ __(':client isn\'t asking for one of your Stars', ['client' => $client->name]) }}
                    @endif
                </flux:heading>

                <div class="mt-3 flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
                    <span>{{ __('Signed in as') }}</span>
                    <flux:avatar size="xs" circle :src="$user->avatar_url" :name="$user->name" :initials="$user->initials()" />
                    <span class="font-medium text-zinc-950 dark:text-white">{{ $user->name }}</span>
                    @if (filled($user->signInName()))
                        <span class="break-all">({{ $user->signInName() }})</span>
                    @endif
                    <span aria-hidden="true">·</span>
                    <form method="POST" action="{{ $switchAccountUrl }}">
                        @csrf

                        <flux:link as="button" type="submit" variant="ghost">{{ __('Not you?') }}</flux:link>
                    </form>
                </div>
            </div>

            @if ($app !== null)
                <x-section-card class="mt-8" :heading="__('What :client can do', ['client' => $client->name])" :description="__('Only approve it if you just added this Star to :client yourself.', ['client' => $client->name])">
                    <ul role="list" class="-my-3 divide-y divide-zinc-200 dark:divide-white/10">
                        <li class="flex gap-3 py-3">
                            <flux:icon.wrench-screwdriver class="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />

                            <div class="min-w-0">
                                <flux:text variant="strong" class="font-medium">
                                    {{ trans_choice('{0} Call the tools you switch on in this Star|{1} Call the :count tool switched on in this Star|[2,*] Call the :count tools switched on in this Star', $toolCount, ['count' => $toolCount]) }}
                                </flux:text>
                                <flux:text class="mt-0.5">
                                    {{ $toolCount === 0 ? __('None are on yet, so it can\'t call anything until you switch some on.') : __('Only the tools that are on: switch one off and it can\'t call it any more.') }}
                                </flux:text>
                            </div>
                        </li>

                        <li class="flex gap-3 py-3">
                            <flux:icon.user class="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />

                            <div class="min-w-0">
                                <flux:text variant="strong" class="font-medium">{{ __('As you') }}</flux:text>
                                <flux:text class="mt-0.5">{{ __('Its calls use your Connections and show in your Activity.') }}</flux:text>
                            </div>
                        </li>

                        <li class="flex gap-3 py-3">
                            <flux:icon.clock class="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400" />

                            <div class="min-w-0">
                                <flux:text variant="strong" class="font-medium">{{ __('Until you revoke it') }}</flux:text>
                                <flux:text class="mt-0.5">{{ __('Revoke it any time on the Star\'s Access page.') }}</flux:text>
                            </div>
                        </li>
                    </ul>

                    <x-slot:hint>
                        {{ __('Authorizing will redirect to') }} <span class="font-mono break-all text-zinc-950 dark:text-white">{{ implode(', ', $app->redirectHosts()) }}</span>
                    </x-slot:hint>

                    <x-slot:actions>
                        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">

                            <flux:button type="submit">{{ __('Deny') }}</flux:button>
                        </form>

                        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
                            @csrf
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">

                            <flux:button type="submit" variant="primary">{{ __('Approve') }}</flux:button>
                        </form>
                    </x-slot:actions>
                </x-section-card>
            @else
                <x-section-card class="mt-8" :heading="__('Nothing to approve')">
                    <flux:callout icon="exclamation-triangle" color="amber">
                        <flux:callout.text>{{ __('It registered with a Star that isn\'t yours, or that no longer lets clients sign in with OAuth. Approving it wouldn\'t give it access, so you can only deny it.') }}</flux:callout.text>
                    </flux:callout>

                    <x-slot:actions>
                        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">

                            <flux:button type="submit">{{ __('Deny') }}</flux:button>
                        </form>
                    </x-slot:actions>
                </x-section-card>
            @endif
        </main>
    </div>
</x-layouts::public>

{{--
    What tells a Connection apart from the user's other accounts of its
    service, inline: the account it signed in as (such as a GitHub login or
    a Notion workspace) and what the user said to use it for, as
    "octocat · Use for: work". Renders nothing when neither is known; with
    `separated`, each part follows a " · ", to come after other text.
--}}
@props([
    'connection',
    'separated' => false,
])

@if (filled($connection->account_identity))
    @if ($separated) · @endif
    <span class="whitespace-nowrap"><flux:icon.user-circle variant="micro" class="inline-block align-[-0.2em]" /><span class="sr-only">{{ __('Signed in as') }}</span> {{ $connection->account_identity }}</span>
@endif
@if (filled($connection->description))
    @if ($separated || filled($connection->account_identity)) · @endif
    {{ __('Use for: :description', ['description' => $connection->description]) }}
@endif

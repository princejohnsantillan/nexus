<x-mail::message>
@if ($reminder === App\Enums\ProReminder::EndsSoon)
# {{ __('Your Pro ends on :date', ['date' => $date]) }}

{{ __('Nexus Pro is prepaid and doesn\'t renew on its own. Yours ends on **:date**. Extend it before then to keep unlimited Stars, Connections and tool calls.', ['date' => $date]) }}

{{ __('If it ends, you\'re back on Free:') }}
@else
# {{ __('You\'re back on Free') }}

{{ __('Your Nexus Pro ended on **:date**, so your account is on Free now:', ['date' => $date]) }}
@endif

- {{ __('**:stars and :connections.** Nothing is deleted: the ones you have past that keep working, you just can\'t add more.', ['stars' => trans_choice(':count Star|:count Stars', $stars), 'connections' => trans_choice(':count Connection|:count Connections', $connections)]) }}
- {{ __('**:calls tool calls a week** across your Stars. Past that, calls are refused until the count resets on Monday.', ['calls' => number_format($toolCalls)]) }}

<x-mail::button :url="$extendUrl" align="left">
{{ __('Extend Pro') }}
</x-mail::button>

@if ($reminder === App\Enums\ProReminder::EndsSoon)
{{ __('Extending adds to the time you have left, so doing it early never loses a day.') }}
@else
{{ __('Extending puts you back on Pro straight away.') }}
@endif
</x-mail::message>

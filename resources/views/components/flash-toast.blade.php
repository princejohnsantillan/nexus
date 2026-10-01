{{--
    Toasts that survive a redirect. Flash `toast` with its text and a variant
    (success, warning or danger):

    to_route('home')->with('toast', ['variant' => 'success', 'text' => __('Saved.')]);
--}}
@persist('toast')
    <flux:toast />
@endpersist

@session('toast')
    <div hidden x-data x-init="$nextTick(() => $flux.toast({ text: $el.textContent.trim(), variant: @js($value['variant'] ?? null) }))">{{ $value['text'] }}</div>
@endsession

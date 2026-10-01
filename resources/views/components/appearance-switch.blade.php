<flux:radio.group x-data variant="segmented" x-model="$flux.appearance" {{ $attributes->merge(['aria-label' => __('Appearance')]) }}>
    <flux:radio value="light" icon="sun">{{ __('Light') }}</flux:radio>
    <flux:radio value="dark" icon="moon">{{ __('Dark') }}</flux:radio>
    <flux:radio value="system" icon="computer-desktop">{{ __('System') }}</flux:radio>
</flux:radio.group>

{{--
    The name agents will see for a Connection's tools, handle__tool, as the
    user types the handle into the Livewire property named by `model`
    (`handle` unless you say), and that the handle can't be changed later.
    Pass `handle` as it is now, and a `tool` the server is known to have;
    without one, the tool is shown as <tool>.
--}}
@props([
    'handle' => '',
    'tool' => null,
    'model' => 'handle',
])

<p {{ $attributes->class('rounded-lg bg-zinc-50 px-3 py-2.5 text-sm text-zinc-500 dark:bg-white/5 dark:text-zinc-400') }} x-data data-handle-preview>
    {{ __('Agents will see') }}
    <span class="font-mono font-medium wrap-anywhere text-zinc-950 dark:text-white"><span x-text="String($wire.{{ $model }} ?? '').trim() || @js(__('handle'))" data-handle-preview-handle>{{ trim($handle) === '' ? __('handle') : trim($handle) }}</span>__<span @class(['font-normal text-zinc-500 dark:text-zinc-400' => $tool === null])>{{ $tool ?? '<tool>' }}</span></span>
    <span aria-hidden="true">·</span>
    {{ __('can\'t change later') }}
</p>

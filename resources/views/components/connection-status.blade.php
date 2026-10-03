{{--
    A Connection's status as a pill with a dot, in its status colour.
--}}
@props([
    'status',
])

<span {{ $attributes->class([
    'inline-flex h-6 shrink-0 items-center gap-1.5 rounded-full px-2.5 text-xs font-medium whitespace-nowrap',
    match ($status) {
        App\Enums\ConnectionStatus::Connected => 'bg-success-wash text-success',
        App\Enums\ConnectionStatus::NeedsAuth => 'bg-warning-wash text-warning',
        App\Enums\ConnectionStatus::Error => 'bg-danger-wash text-danger',
        App\Enums\ConnectionStatus::Pending => 'bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    },
]) }} data-connection-status="{{ $status->value }}">
    <span @class([
        'size-1.5 shrink-0 rounded-full',
        'bg-current' => $status !== App\Enums\ConnectionStatus::Pending,
        'bg-zinc-400' => $status === App\Enums\ConnectionStatus::Pending,
    ])></span>
    {{ $status->label() }}
</span>

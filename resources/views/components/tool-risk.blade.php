{{--
    A tool's risk group (App\Enums\ToolRisk) as a badge: read-only green,
    writes blue, destructive red, not declared neutral.
--}}
@props([
    'risk',
])

<span {{ $attributes->class(['inline-flex h-5.5 items-center rounded-md px-1.5 text-xs font-medium whitespace-nowrap', match ($risk) {
    App\Enums\ToolRisk::ReadOnly => 'bg-success-wash text-success',
    App\Enums\ToolRisk::Writes => 'bg-accent-wash text-accent-content',
    App\Enums\ToolRisk::Destructive => 'bg-danger-wash text-danger',
    App\Enums\ToolRisk::NotDeclared => 'bg-zinc-50 text-zinc-600 ring-1 ring-zinc-200 ring-inset dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
}]) }}>{{ $risk->label() }}</span>

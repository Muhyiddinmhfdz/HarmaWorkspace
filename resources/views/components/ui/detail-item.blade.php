@props([
    'label',
])

<div {{ $attributes->class('flex flex-col gap-1 py-3 sm:flex-row sm:gap-4') }}>
    <dt class="w-32 shrink-0 text-sm text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
    <dd class="min-w-0 text-sm [overflow-wrap:anywhere] text-zinc-800 dark:text-zinc-100">
        @if ($slot->isEmpty() || trim($slot) === '')
            <span class="text-zinc-400">—</span>
        @else
            {{ $slot }}
        @endif
    </dd>
</div>

@props([
    'value' => 0,
    'showLabel' => true,
])

@php($value = max(0, min(100, (int) $value)))

<div {{ $attributes->class('flex items-center gap-2') }}>
    <div class="h-1.5 w-full min-w-16 overflow-hidden rounded-full bg-zinc-100" role="progressbar" aria-valuenow="{{ $value }}" aria-valuemin="0" aria-valuemax="100" aria-label="Progress {{ $value }}%">
        <div class="h-full rounded-full bg-accent transition-all" style="width: {{ $value }}%"></div>
    </div>
    @if ($showLabel)
        <span class="w-9 shrink-0 text-end text-xs font-medium tabular-nums text-zinc-600">{{ $value }}%</span>
    @endif
</div>

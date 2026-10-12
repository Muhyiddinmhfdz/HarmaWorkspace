{{-- Badge for a colour-coded master (status, priority, health). Colour is never the only signal: the name is always shown. --}}
@props([
    'master' => null,
    'size' => 'sm',
])

@if ($master)
    <flux:badge {{ $attributes }} :color="$master->color ?? 'zinc'" :size="$size" inset="top bottom">{{ $master->name }}</flux:badge>
@else
    <span class="text-zinc-400">—</span>
@endif

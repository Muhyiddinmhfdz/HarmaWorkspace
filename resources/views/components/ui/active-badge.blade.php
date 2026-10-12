@props([
    'active' => true,
])

@if ($active)
    <flux:badge {{ $attributes }} color="green" size="sm" inset="top bottom">Active</flux:badge>
@else
    <flux:badge {{ $attributes }} color="zinc" size="sm" inset="top bottom">Inactive</flux:badge>
@endif

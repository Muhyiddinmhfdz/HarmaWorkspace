@props([
    'date',
    'done' => false,
    'compact' => false,
])

@php($deadline = \App\Domain\Project\Support\Deadline::for($date, $done))

<div {{ $attributes->class('leading-tight') }}>
    <div class="text-sm text-zinc-800">{{ $date->translatedFormat('d M Y') }}</div>
    @unless ($compact && $done)
        <div @class([
            'text-xs',
            'text-red-600' => $deadline->tone === 'red',
            'text-amber-600' => $deadline->tone === 'amber',
            'text-zinc-500' => $deadline->tone === 'zinc',
        ])>{{ $deadline->label }}</div>
    @endunless
</div>

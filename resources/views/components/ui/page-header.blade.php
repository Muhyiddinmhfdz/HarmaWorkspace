@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class('mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        @isset($breadcrumbs)
            <div class="mb-2">{{ $breadcrumbs }}</div>
        @endisset

        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>

        @if ($description)
            <flux:text class="mt-1">{{ $description }}</flux:text>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            {{ $actions }}
        </div>
    @endisset
</div>

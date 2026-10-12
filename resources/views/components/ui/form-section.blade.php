@props([
    'title',
    'description' => null,
])

<section {{ $attributes->class('grid gap-6 border-b border-zinc-200 py-8 first:pt-0 last:border-b-0 md:grid-cols-3 dark:border-zinc-700') }}>
    <div>
        <flux:heading size="lg" level="2">{{ $title }}</flux:heading>
        @if ($description)
            <flux:text class="mt-1">{{ $description }}</flux:text>
        @endif
    </div>

    <div class="space-y-6 md:col-span-2">
        {{ $slot }}
    </div>
</section>

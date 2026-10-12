@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<div {{ $attributes->class('flex flex-col items-center justify-center px-6 py-14 text-center') }}>
    <div class="mb-4 flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-700/60">
        <flux:icon :icon="$icon" class="size-6 text-zinc-500 dark:text-zinc-300" />
    </div>

    <flux:heading size="lg" level="3">{{ $title }}</flux:heading>

    @if ($description)
        <flux:text class="mt-1 max-w-md">{{ $description }}</flux:text>
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            {{ $slot }}
        </div>
    @endif
</div>

<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="min-w-0 max-lg:pb-24">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>

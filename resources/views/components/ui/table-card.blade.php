{{-- Bordered container for list tables; horizontal scroll on small screens. --}}
<div {{ $attributes->class('relative overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900') }}>
    {{ $slot }}
</div>

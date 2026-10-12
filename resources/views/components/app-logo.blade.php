@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="HarmaWorkspace" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent text-accent-foreground">
            <x-app-logo-icon class="size-4 fill-current" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="HarmaWorkspace" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent text-accent-foreground">
            <x-app-logo-icon class="size-4 fill-current" />
        </x-slot>
    </flux:brand>
@endif

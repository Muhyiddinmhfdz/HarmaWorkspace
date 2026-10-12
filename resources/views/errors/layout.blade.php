{{-- Standalone error layout: no database or session dependencies, so it renders even when those fail. --}}
<!DOCTYPE html>
<html lang="id">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>@yield('code') · HarmaWorkspace</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="min-h-screen bg-white text-zinc-800 antialiased">
        <main class="flex min-h-svh flex-col items-center justify-center px-6 text-center">
            <span class="mb-6 flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground">
                <x-app-logo-icon class="size-6 fill-current" />
            </span>
            <p class="font-mono text-sm text-zinc-400">@yield('code')</p>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight">@yield('title')</h1>
            <p class="mt-2 max-w-md text-zinc-500 dark:text-zinc-400">@yield('message')</p>
            <div class="mt-8 flex gap-3">
                <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/') }}" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium hover:bg-white dark:border-zinc-700 dark:hover:bg-zinc-800">Kembali</a>
                <a href="{{ url('/dashboard') }}" class="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-accent-foreground hover:opacity-90">Ke Dashboard</a>
            </div>
        </main>
    </body>
</html>

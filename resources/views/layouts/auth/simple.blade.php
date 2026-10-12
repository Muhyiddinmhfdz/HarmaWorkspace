@php
    $subtitle = app(\App\Domain\System\Settings\Settings::class)->get(\App\Domain\System\Settings\SettingKey::AppSubtitle);
    $organization = app(\App\Domain\System\Settings\Settings::class)->get(\App\Domain\System\Settings\SettingKey::OrganizationName);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-800 antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <div class="flex flex-col items-center gap-3 text-center">
                    <span class="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground shadow-sm">
                        <x-app-logo-icon class="size-6 fill-current" />
                    </span>
                    <div>
                        <div class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white">
                            Harma<span class="text-accent-content">Workspace</span>
                        </div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $subtitle }}</div>
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
                    {{ $slot }}
                </div>

                <p class="text-center text-xs text-zinc-400">
                    Sistem internal {{ $organization }}. Akun dibuat oleh administrator.
                </p>
            </div>
        </div>

        @php
            $flash = match (true) {
                $errors->any() => ['type' => 'error', 'message' => $errors->first()],
                session()->has('status') => ['type' => 'success', 'message' => session('status')],
                session()->has('notify') => session('notify'),
                default => null,
            };
        @endphp
        @if ($flash)
            <div hidden data-flash-notify="{{ json_encode($flash) }}"></div>
        @endif

        @fluxScripts
    </body>
</html>

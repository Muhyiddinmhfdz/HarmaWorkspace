<x-layouts::auth title="Sign in">
    <div class="flex flex-col gap-6">
        <x-auth-header title="Sign in" description="Masuk menggunakan username dan password akun HarmaWorkspace Anda." />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="username"
                label="Username"
                :value="old('username')"
                type="text"
                required
                autofocus
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    label="Password"
                    type="password"
                    required
                    autocomplete="current-password"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute end-0 top-0 text-sm" :href="route('password.request')" wire:navigate>
                        Forgot password?
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" label="Remember me" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                Sign in
            </flux:button>
        </form>
    </div>
</x-layouts::auth>

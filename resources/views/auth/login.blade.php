<x-layouts.storefront title="Sign in">
    <div class="container-page max-w-md py-12 lg:py-16">
        <h1 class="text-3xl">Sign in</h1>
        <p class="mt-2 text-ink-500">Welcome back.</p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="email" class="field-input" required autofocus
                       aria-invalid="@error('email')true @else false @enderror">
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="field-label">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password"
                       class="field-input" required>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="remember" class="size-4 rounded accent-[--brand-accent]">
                    Remember me
                </label>
                <a href="{{ route('password.request') }}" class="text-sm underline">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary w-full">Sign in</button>
        </form>

        <p class="mt-6 text-center text-sm text-ink-500">
            New here? <a href="{{ route('register') }}" class="underline">Create an account</a>,
            or <a href="{{ route('orders.track') }}" class="underline">track a guest order</a>.
        </p>
    </div>
</x-layouts.storefront>

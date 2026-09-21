<x-layouts.storefront title="Create an account">
    <div class="container-page max-w-md py-12 lg:py-16">
        <h1 class="text-3xl">Create an account</h1>
        <p class="mt-2 text-ink-500">Optional — you can check out as a guest at any time.</p>

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="name" class="field-label">Name</label>
                <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" class="field-input" required autofocus>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" class="field-input" required>
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="field-label">Phone <span class="text-ink-400">(optional)</span></label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" class="field-input">
            </div>
            <div>
                <label for="password" class="field-label">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" class="field-input" required>
                <p class="mt-1 text-xs text-ink-400">At least 10 characters, with upper and lower case and a number.</p>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="field-label">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" class="field-input" required>
            </div>
            <button type="submit" class="btn btn-primary w-full">Create account</button>
        </form>

        <p class="mt-6 text-center text-sm text-ink-500">
            Already have an account? <a href="{{ route('login') }}" class="underline">Sign in</a>.
        </p>
    </div>
</x-layouts.storefront>

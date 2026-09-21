<x-layouts.storefront title="Reset your password">
    <div class="container-page max-w-md py-12 lg:py-16">
        <h1 class="text-3xl">Reset your password</h1>
        <p class="mt-2 text-ink-500">Enter your email and we will send a reset link.</p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="email" class="field-input" required autofocus>
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary w-full">Send reset link</button>
        </form>
    </div>
</x-layouts.storefront>

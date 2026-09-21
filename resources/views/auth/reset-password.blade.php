<x-layouts.storefront title="Choose a new password">
    <div class="container-page max-w-md py-12 lg:py-16">
        <h1 class="text-3xl">Choose a new password</h1>

        <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}"
                       autocomplete="email" class="field-input" required readonly>
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password" class="field-label">New password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" class="field-input" required autofocus>
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="field-label">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password"
                       autocomplete="new-password" class="field-input" required>
            </div>
            <button type="submit" class="btn btn-primary w-full">Reset password</button>
        </form>
    </div>
</x-layouts.storefront>

<x-layouts.storefront title="Your profile">
    <div class="container-page max-w-lg py-10 lg:py-14">
        <h1 class="mb-8 text-3xl">Your profile</h1>

        @if (session('status'))
            <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label for="name" class="field-label">Name</label>
                <input id="name" name="name" value="{{ old('name', auth()->user()->name) }}" class="field-input" required>
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="field-label">Email</label>
                <input id="email" value="{{ auth()->user()->email }}" class="field-input bg-cream-100" disabled>
                <p class="mt-1 text-xs text-ink-400">Contact us if you need to change the address on your account.</p>
            </div>

            <div>
                <label for="phone" class="field-label">Phone</label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone', auth()->user()->phone) }}" class="field-input">
            </div>

            <label class="flex items-start gap-2.5 text-sm">
                <input type="checkbox" name="accepts_marketing" value="1"
                       @checked(old('accepts_marketing', auth()->user()->accepts_marketing))
                       class="mt-0.5 size-4 rounded accent-[--brand-accent]">
                <span>Email me occasional product news. You can turn this off at any time.</span>
            </label>

            <button type="submit" class="btn btn-primary">Save changes</button>
        </form>
    </div>
</x-layouts.storefront>

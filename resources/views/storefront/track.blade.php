<x-layouts.storefront title="Track your order">
    <div class="container-page max-w-lg py-12 lg:py-16">
        <h1 class="text-3xl">Track your order</h1>
        <p class="mt-2 text-ink-500">
            Enter your order number and the email address you used. We will send a secure link to view it.
        </p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('orders.track.request') }}" class="mt-6 space-y-4">
            @csrf
            <div>
                <label for="order_number" class="field-label">Order number</label>
                <input id="order_number" name="order_number" value="{{ old('order_number') }}"
                       class="field-input" placeholder="PS-260921-ABCDE" required
                       aria-invalid="@error('order_number')true @else false @enderror">
                @error('order_number') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="track_email" class="field-label">Email address</label>
                <input id="track_email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="email" class="field-input" required>
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="btn btn-primary w-full">Email me a secure link</button>
        </form>

        <p class="mt-4 text-xs text-ink-400">
            We send a link rather than showing the order straight away, so that only the person who can read
            that inbox can see the order details.
        </p>
    </div>
</x-layouts.storefront>

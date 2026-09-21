<x-layouts.storefront title="Verify your email">
    <div class="container-page max-w-md py-12 lg:py-16">
        <h1 class="text-3xl">Verify your email</h1>
        <p class="mt-2 text-ink-500">
            We sent a link to {{ auth()->user()->email }}. Click it to finish setting up your account.
        </p>
        <p class="mt-3 text-sm text-ink-500">
            Verifying also lets us safely attach any orders you placed as a guest with this address.
        </p>

        @if (session('status'))
            <x-ui.alert type="success" class="mt-6">{{ session('status') }}</x-ui.alert>
        @endif

        <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
            @csrf
            <button type="submit" class="btn btn-secondary w-full">Resend the link</button>
        </form>
    </div>
</x-layouts.storefront>

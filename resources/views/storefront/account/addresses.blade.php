<x-layouts.storefront title="Your addresses">
    <div class="container-page max-w-3xl py-10 lg:py-14">
        <h1 class="mb-8 text-3xl">Your addresses</h1>

        @if ($addresses->isEmpty())
            <x-ui.empty-state title="No saved addresses"
                              description="Addresses you use at checkout can be saved here for next time." />
        @else
            <ul class="grid gap-4 sm:grid-cols-2">
                @foreach ($addresses as $address)
                    <li class="card p-5">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-medium text-ink-900">{{ $address->fullName() }}</p>
                            @if ($address->is_default_shipping)
                                <span class="badge bg-accent-50 text-accent-700">Default</span>
                            @endif
                        </div>
                        <address class="mt-1.5 text-sm not-italic text-ink-500">{{ $address->singleLine() }}</address>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-layouts.storefront>

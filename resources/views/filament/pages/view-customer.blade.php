<x-filament-panels::page>
    @php
        /** @var \App\Models\User $customer */
        $customer = $this->record->loadMissing(['addresses']);
        $orders = $customer->orders()->latest()->limit(25)->get();
        $realOrders = $orders->where('is_demo', false);
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <x-filament::section heading="Customer" class="lg:col-span-1">
            <dl class="space-y-2 text-sm">
                <div><dt class="text-xs text-gray-500">Name</dt><dd>{{ $customer->name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Email</dt><dd>{{ $customer->email }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-500">Email verified</dt>
                    <dd>{{ $customer->email_verified_at?->format('j M Y') ?? 'Not verified' }}</dd>
                </div>
                <div><dt class="text-xs text-gray-500">Phone</dt><dd>{{ $customer->phone ?: '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Joined</dt><dd>{{ $customer->created_at->format('j M Y') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Last seen</dt><dd>{{ $customer->last_login_at?->diffForHumans() ?? 'Never signed in' }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-500">Marketing</dt>
                    <dd>{{ $customer->accepts_marketing ? 'Opted in' : 'Not opted in' }}</dd>
                </div>
            </dl>

            @unless ($customer->email_verified_at)
                <p class="mt-3 rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
                    This address is unverified, so any guest orders placed with it have deliberately
                    <strong>not</strong> been attached to this account.
                </p>
            @endunless
        </x-filament::section>

        <x-filament::section heading="Addresses" class="lg:col-span-2">
            @forelse ($customer->addresses as $address)
                <div class="border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-800">
                    <p class="font-medium">
                        {{ $address->fullName() }}
                        @if ($address->is_default_shipping)
                            <span class="ml-1 text-xs text-gray-500">(default)</span>
                        @endif
                    </p>
                    <p class="text-gray-500">{{ $address->singleLine() }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500">No saved addresses.</p>
            @endforelse
        </x-filament::section>
    </div>

    <x-filament::section heading="Orders">
        @if ($orders->isEmpty())
            <p class="text-sm text-gray-500">No orders yet.</p>
        @else
            <p class="mb-3 text-sm text-gray-500">
                {{ $realOrders->count() }} real order(s)
                totalling {{ format_minor((int) $realOrders->sum(fn ($o) => (int) $o->getRawOriginal('total_minor')), 'USD') }} gross.
                @if ($orders->count() !== $realOrders->count())
                    {{ $orders->count() - $realOrders->count() }} demo order(s) are shown but excluded from that figure.
                @endif
            </p>

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-gray-500">
                    <tr><th class="pb-2">Order</th><th class="pb-2">Placed</th><th class="pb-2">Status</th><th class="pb-2 text-right">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($orders as $order)
                        <tr>
                            <td class="py-2">
                                <a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $order]) }}"
                                   class="font-medium text-primary-600 hover:underline">{{ $order->number }}</a>
                                @if ($order->is_demo)
                                    <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-900">demo</span>
                                @endif
                            </td>
                            <td class="py-2 text-gray-500">{{ $order->placed_at?->format('j M Y') }}</td>
                            <td class="py-2">{{ ucfirst(str_replace('_', ' ', $order->lifecycle_status)) }}</td>
                            <td class="py-2 text-right">{{ format_minor((int) $order->getRawOriginal('total_minor'), $order->currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>

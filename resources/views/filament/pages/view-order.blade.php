<x-filament-panels::page>
    @php
        /** @var \App\Models\Order $order */
        $order = $this->record->load([
            'items.variant', 'items.warehouse', 'payments', 'refunds',
            'fulfilments.warehouse', 'fulfilments.supplierPayment', 'fulfilments.items',
            'shipments', 'exceptions', 'stateEvents.actor', 'returnRequests',
        ]);
        $gaps = $order->costGaps();
        $contribution = $order->contributionMinor();
    @endphp

    @if ($order->is_demo)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            <p class="font-semibold">Demonstration order</p>
            <p>{{ $order->demo_reason ?: 'Settled with a simulated payment.' }}
               It is excluded from revenue reporting and cannot be fulfilled through a live supplier.</p>
        </div>
    @endif

    @if ($order->exceptions->where('state', '!=', 'resolved')->isNotEmpty())
        <div class="space-y-2">
            @foreach ($order->exceptions->whereIn('state', ['open', 'acknowledged']) as $exception)
                <div @class([
                    'rounded-lg border p-4 text-sm',
                    'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200' => $exception->severity === 'critical',
                    'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200' => $exception->severity !== 'critical',
                ])>
                    <p class="font-semibold">{{ $exception->title }}</p>
                    @if ($exception->detail)<p class="mt-1">{{ $exception->detail }}</p>@endif
                    @if ($exception->suggested_action)
                        <p class="mt-2"><span class="font-medium">Next step:</span> {{ $exception->suggested_action }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- The five state machines, shown side by side and never merged. --}}
    <x-filament::section heading="Order state" description="These five things move independently. Paid does not mean ordered, and ordered does not mean paid for.">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ([
                'Customer payment' => $order->payment_state,
                'Admin approval' => $order->approval_state,
                'Supplier order' => $order->supplier_order_state,
                'Supplier payment' => $order->supplier_payment_state,
                'Shipment' => $order->shipment_state,
            ] as $label => $state)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-sm font-medium">{{ ucfirst(str_replace('_', ' ', $state)) }}</p>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <x-filament::section heading="Items">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="pb-2">Item</th><th class="pb-2">Warehouse</th>
                            <th class="pb-2 text-right">Qty</th><th class="pb-2 text-right">Cost</th>
                            <th class="pb-2 text-right">Charged</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="py-2">
                                    <p class="font-medium">{{ $item->name }}</p>
                                    <p class="text-xs text-gray-500">{{ $item->sku }}{{ $item->option_summary ? ' · '.$item->option_summary : '' }}</p>
                                </td>
                                <td class="py-2 text-gray-500">{{ $item->warehouse?->code ?? '—' }}</td>
                                <td class="py-2 text-right">{{ $item->quantity }}</td>
                                <td class="py-2 text-right text-gray-500">
                                    {{ format_minor($item->getRawOriginal('supplier_cost_minor'), $item->supplier_cost_currency ?? 'USD') }}
                                </td>
                                <td class="py-2 text-right">{{ format_minor($item->lineTotalMinor(), $order->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>

            @if ($order->fulfilments->isNotEmpty())
                <x-filament::section heading="Fulfilments">
                    <div class="space-y-3">
                        @foreach ($order->fulfilments as $fulfilment)
                            <div class="rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p class="font-medium">{{ $fulfilment->internal_reference }}</p>
                                        <p class="text-xs text-gray-500">
                                            {{ $fulfilment->warehouse?->label() ?? 'No warehouse' }} ·
                                            {{ ucfirst(str_replace('_', ' ', $fulfilment->state)) }} ·
                                            mode: {{ $fulfilment->mode }}
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <p>{{ format_minor($fulfilment->totalCostMinor(), $fulfilment->currency) }} cost</p>
                                        <p class="text-xs text-gray-500">
                                            Supplier payment:
                                            {{ $fulfilment->supplierPayment?->status ?? 'not started' }}
                                        </p>
                                    </div>
                                </div>
                                @if ($fulfilment->supplier_order_id)
                                    <p class="mt-1 text-xs text-gray-500">Supplier order {{ $fulfilment->supplier_order_id }}</p>
                                @endif
                                @if ($fulfilment->last_error)
                                    <p class="mt-1 text-xs text-red-600">{{ $fulfilment->last_error }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($order->shipments->isNotEmpty())
                <x-filament::section heading="Shipments">
                    <div class="space-y-2 text-sm">
                        @foreach ($order->shipments as $shipment)
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                <div>
                                    <p class="font-medium">{{ $shipment->carrier ?: 'Carrier not reported' }}</p>
                                    <p class="text-xs text-gray-500">{{ $shipment->estimateLabel() }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-mono text-xs">{{ $shipment->tracking_number ?: 'No tracking yet' }}</p>
                                    <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $shipment->state)) }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            <x-filament::section heading="History" collapsible collapsed>
                <ol class="space-y-2 text-sm">
                    @foreach ($order->stateEvents as $event)
                        <li class="flex flex-wrap gap-2 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                            <span class="text-xs text-gray-400">{{ $event->created_at?->format('j M H:i') }}</span>
                            <span>{{ $event->describe() }}</span>
                            <span class="text-xs text-gray-500">
                                by {{ $event->actor?->name ?? $event->actor_type }}
                            </span>
                            @if ($event->reason)<span class="text-xs text-gray-400">— {{ $event->reason }}</span>@endif
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            <x-filament::section heading="Customer">
                <dl class="space-y-1.5 text-sm">
                    <div><dt class="text-xs text-gray-500">Email</dt><dd>{{ $order->email }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Phone</dt><dd>{{ $order->phone ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Type</dt><dd>{{ $order->is_guest ? 'Guest checkout' : 'Account' }}</dd></div>
                    <div class="pt-2">
                        <dt class="text-xs text-gray-500">Delivery address</dt>
                        <dd class="whitespace-pre-line">{{ collect([
                            trim(data_get($order->shipping_address, 'first_name').' '.data_get($order->shipping_address, 'last_name')),
                            data_get($order->shipping_address, 'line1'),
                            data_get($order->shipping_address, 'line2'),
                            trim(data_get($order->shipping_address, 'city').', '.data_get($order->shipping_address, 'state').' '.data_get($order->shipping_address, 'postal_code')),
                            data_get($order->shipping_address, 'country_code'),
                        ])->filter()->implode("\n") }}</dd>
                    </div>
                </dl>
            </x-filament::section>

            <x-filament::section heading="Money">
                <dl class="space-y-1.5 text-sm">
                    @foreach ([
                        'Subtotal' => 'subtotal_minor',
                        'Discount' => 'discount_minor',
                        'Delivery charged' => 'shipping_minor',
                        'Tax' => 'tax_minor',
                        'Total' => 'total_minor',
                        'Refunded' => 'refunded_minor',
                    ] as $label => $field)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd @class(['font-medium' => $label === 'Total'])>
                                {{ format_minor((int) $order->getRawOriginal($field), $order->currency) }}
                            </dd>
                        </div>
                    @endforeach

                    <div class="border-t border-gray-200 pt-2 dark:border-gray-700"></div>

                    @foreach ([
                        'Merchandise cost' => 'merchandise_cost_minor',
                        'Supplier shipping' => 'supplier_shipping_cost_minor',
                        'Packaging' => 'packaging_cost_minor',
                        'Gateway fee (actual)' => 'gateway_fee_actual_minor',
                    ] as $label => $field)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ $label }}</dt>
                            <dd>{{ format_minor($order->getRawOriginal($field) === null ? null : (int) $order->getRawOriginal($field), $order->currency, 'not known') }}</dd>
                        </div>
                    @endforeach

                    <div class="border-t border-gray-200 pt-2 dark:border-gray-700"></div>

                    <div class="flex justify-between">
                        {{-- Never labelled "net profit": advertising and
                             overhead are not known here. --}}
                        <dt class="font-medium">Contribution</dt>
                        <dd class="font-medium">{{ format_minor($contribution, $order->currency, 'cannot be calculated') }}</dd>
                    </div>
                    <p class="text-xs text-gray-500">
                        Before advertising and overhead.
                        @if ($gaps !== [])
                            <span class="text-amber-600">Incomplete: {{ implode(', ', $gaps) }} {{ count($gaps) === 1 ? 'is' : 'are' }} not yet known.</span>
                        @endif
                    </p>
                </dl>
            </x-filament::section>

            @if ($order->payments->isNotEmpty())
                <x-filament::section heading="Payments">
                    <div class="space-y-2 text-sm">
                        @foreach ($order->payments as $payment)
                            <div class="rounded border border-gray-200 p-2 dark:border-gray-700">
                                <div class="flex justify-between">
                                    <span class="font-medium">{{ $payment->gateway_code }}</span>
                                    <span>{{ format_minor((int) $payment->getRawOriginal('amount_minor'), $payment->currency) }}</span>
                                </div>
                                <p class="text-xs text-gray-500">
                                    {{ $payment->status }} · {{ $payment->mode }} mode
                                    @if ($payment->provider_reference) · {{ $payment->provider_reference }} @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>

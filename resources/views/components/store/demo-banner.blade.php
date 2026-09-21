@php
    $demoSuppliers = \App\Models\Supplier::query()->where('mode', '!=', 'live')->where('is_enabled', true)->pluck('name');
    $demoGateways = \App\Models\PaymentGateway::query()->where('is_enabled', true)->where('mode', '!=', 'live')->pluck('name');
    $anyDemo = $demoSuppliers->isNotEmpty() || $demoGateways->isNotEmpty();
@endphp

@if ($anyDemo && config('petstore.demo.banner'))
    {{-- Demo mode must be unmistakable. No order placed while this is
         showing results in a real charge or a real supplier purchase. --}}
    <div role="status"
         class="border-b border-amber-300 bg-amber-100 px-4 py-2.5 text-center text-sm text-amber-950">
        <span class="font-semibold">Demonstration mode.</span>
        @if ($demoGateways->isNotEmpty())
            Payments are simulated ({{ $demoGateways->implode(', ') }}) — no money is taken.
        @endif
        @if ($demoSuppliers->isNotEmpty())
            Supplier data is simulated ({{ $demoSuppliers->implode(', ') }}) — no orders are placed with a supplier.
        @endif
    </div>
@endif

<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Adapters\Demo;

use App\Domain\Supplier\Contracts\SupplierAdapter;
use App\Domain\Supplier\DTO\AuthResult;
use App\Domain\Supplier\DTO\ProductSearchQuery;
use App\Domain\Supplier\DTO\ProductSearchResult;
use App\Domain\Supplier\DTO\ShippingQuoteRequest;
use App\Domain\Supplier\DTO\SupplierOrderRequest;
use App\Domain\Supplier\DTO\SupplierOrderResult;
use App\Domain\Supplier\DTO\SupplierPaymentResult;
use App\Domain\Supplier\DTO\SupplierProduct;
use App\Domain\Supplier\DTO\SupplierShippingOption;
use App\Domain\Supplier\DTO\SupplierVariant;
use App\Domain\Supplier\DTO\TrackingResult;
use App\Domain\Supplier\DTO\WarehouseStockReading;
use App\Domain\Supplier\Exceptions\SupplierOperationUnsupported;
use App\Domain\Supplier\Exceptions\SupplierRequestFailed;
use App\Models\ShippingQuote;
use App\Models\Supplier;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for CJdropshipping.
 *
 * Everything is derived from a hash of the input, so the same input always
 * produces the same output and tests do not need network access. It is
 * built to exercise the awkward cases, not just the happy path:
 *
 *   - variants with genuinely unknown stock (null, not zero)
 *   - a service that supplies no delivery estimate at all
 *   - business-days vs calendar-days estimates side by side
 *   - split shipments across two warehouses
 *   - out-of-stock failure on submission
 *   - a supplier balance too low to pay
 *   - a timeout whose outcome is unknown
 *
 * Demo scenarios are selected by SKU/reference suffix; see
 * docs/demo-scenarios.md.
 */
class DemoSupplierAdapter implements SupplierAdapter
{
    private const ORDER_CACHE_PREFIX = 'demo.supplier.order.';

    public function __construct(private readonly Supplier $supplier) {}

    public function supplier(): Supplier
    {
        return $this->supplier;
    }

    public function mode(): string
    {
        return Supplier::MODE_DEMO;
    }

    public function isDemo(): bool
    {
        return true;
    }

    public function authenticate(): AuthResult
    {
        return new AuthResult(true, 'Demo adapter — no credentials required.', now()->addYear());
    }

    /* ------------------------------------------------------------------ */
    /* Catalogue                                                           */
    /* ------------------------------------------------------------------ */

    public function searchProducts(ProductSearchQuery $query): ProductSearchResult
    {
        $catalogue = DemoCatalogue::products();

        if (filled($query->keyword)) {
            $needle = Str::lower($query->keyword);
            $catalogue = array_values(array_filter(
                $catalogue,
                fn (array $p) => str_contains(Str::lower($p['name']), $needle)
            ));
        }

        $offset = ($query->page - 1) * $query->pageSize;
        $page = array_slice($catalogue, $offset, $query->pageSize);

        return new ProductSearchResult(
            products: array_map(fn (array $p) => $this->toProduct($p), $page),
            page: $query->page,
            pageSize: $query->pageSize,
            totalCount: count($catalogue),
            hasMore: ($offset + $query->pageSize) < count($catalogue),
        );
    }

    public function fetchProduct(string $supplierProductId): ?SupplierProduct
    {
        $row = DemoCatalogue::find($supplierProductId);

        return $row === null ? null : $this->toProduct($row);
    }

    public function fetchStock(array $supplierVariantIds): array
    {
        $out = [];

        foreach ($supplierVariantIds as $vid) {
            $out[$vid] = DemoCatalogue::stockFor($vid);
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Shipping                                                            */
    /* ------------------------------------------------------------------ */

    public function quoteShipping(ShippingQuoteRequest $request): array
    {
        $country = strtoupper($request->destinationCountry);

        if ($country !== 'US') {
            // Demonstrates a destination with no eligible service at all.
            return [];
        }

        $weight = 0;
        foreach ($request->lines as $line) {
            $weight += ($line['weight_grams'] ?? 500) * $line['quantity'];
        }

        $base = 499 + (int) floor($weight / 500) * 120;
        $zip = (string) $request->destinationPostalCode;

        // Alaska/Hawaii prefixes cost more and take longer, so the storefront's
        // exclusion and surcharge handling can be exercised.
        $remote = str_starts_with($zip, '99') || str_starts_with($zip, '96');

        $options = [
            new SupplierShippingOption(
                serviceName: 'Demo US Standard',
                serviceCode: 'DEMO_US_STD',
                cost: Money::ofMinor($remote ? $base + 900 : $base, 'USD'),
                estimateMin: $remote ? 6 : 3,
                estimateMax: $remote ? 12 : 7,
                estimateUnit: ShippingQuote::UNIT_BUSINESS_DAYS,
                estimateType: ShippingQuote::TYPE_TRANSIT,
                warehouseCode: $request->warehouseCode ?? 'US-NJ',
                raw: ['demo' => true, 'scenario' => 'standard'],
            ),
            new SupplierShippingOption(
                serviceName: 'Demo US Expedited',
                serviceCode: 'DEMO_US_EXP',
                cost: Money::ofMinor($base + 1400, 'USD'),
                estimateMin: 2,
                estimateMax: 4,
                // Deliberately calendar days, to prove the two units are
                // stored and displayed distinctly.
                estimateUnit: ShippingQuote::UNIT_DAYS,
                estimateType: ShippingQuote::TYPE_TRANSIT,
                warehouseCode: $request->warehouseCode ?? 'US-NJ',
                raw: ['demo' => true, 'scenario' => 'expedited'],
            ),
            new SupplierShippingOption(
                serviceName: 'Demo Economy (no estimate published)',
                serviceCode: 'DEMO_US_ECON',
                cost: Money::ofMinor(max(299, $base - 200), 'USD'),
                // No estimate at all: the storefront must say so honestly.
                estimateMin: null,
                estimateMax: null,
                estimateUnit: ShippingQuote::UNIT_UNKNOWN,
                estimateType: ShippingQuote::TYPE_UNKNOWN,
                warehouseCode: $request->warehouseCode ?? 'US-NJ',
                raw: ['demo' => true, 'scenario' => 'no_estimate'],
            ),
        ];

        return $options;
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    public function createOrder(SupplierOrderRequest $request): SupplierOrderResult
    {
        $scenario = DemoScenario::forReference($request->internalReference);

        if ($scenario === DemoScenario::TIMEOUT) {
            // Record the order first, then fail: exactly the dangerous case
            // where the supplier may have acted but we never heard back.
            $this->rememberOrder($request, 'created');

            throw new SupplierRequestFailed(
                'Demo: the supplier did not respond before the timeout. The outcome is unknown.',
                endpoint: 'order_create',
                outcomeUnknown: true,
            );
        }

        if ($scenario === DemoScenario::OUT_OF_STOCK) {
            throw new SupplierRequestFailed(
                'Demo: one or more variants went out of stock before the order could be placed.',
                endpoint: 'order_create',
                providerCode: 'DEMO_OUT_OF_STOCK',
            );
        }

        if ($request->packagingId !== null && ! $this->supplier->supports('packaging_selection')) {
            throw new SupplierOperationUnsupported(
                'packaging_selection',
                'Demo: packaging selection over the API is not enabled for this account.',
                manualWorkaround: 'Arrange packaging manually with the supplier and record it on the packaging record.',
            );
        }

        $stored = $this->rememberOrder($request, 'confirmed');

        return $this->resultFrom($stored);
    }

    public function findOrderByInternalReference(string $internalReference): ?SupplierOrderResult
    {
        $stored = Cache::get(self::ORDER_CACHE_PREFIX.$internalReference);

        return is_array($stored) ? $this->resultFrom($stored) : null;
    }

    public function getOrder(string $supplierOrderId): ?SupplierOrderResult
    {
        $reference = Cache::get(self::ORDER_CACHE_PREFIX.'id.'.$supplierOrderId);

        return is_string($reference) ? $this->findOrderByInternalReference($reference) : null;
    }

    public function payOrder(string $supplierOrderId, Money $expectedAmount): SupplierPaymentResult
    {
        $order = $this->getOrder($supplierOrderId);

        if ($order === null) {
            return new SupplierPaymentResult(
                paid: false,
                status: 'unknown_order',
                failureReason: 'Demo: no such supplier order. Reconcile before retrying.',
            );
        }

        $scenario = DemoScenario::forReference((string) $order->internalReference);

        if ($scenario === DemoScenario::INSUFFICIENT_BALANCE) {
            return new SupplierPaymentResult(
                paid: false,
                status: 'insufficient_balance',
                failureReason: 'Demo: the supplier account balance is too low to pay this order.',
                raw: ['demo' => true],
            );
        }

        return new SupplierPaymentResult(
            paid: true,
            status: 'paid',
            amount: $expectedAmount,
            reference: 'DEMO-PAY-'.substr(hash('sha256', $supplierOrderId), 0, 12),
            raw: ['demo' => true],
        );
    }

    public function getBalance(): ?Money
    {
        return Money::ofMinor(250_00, 'USD');
    }

    public function trackShipment(string $trackingNumber): ?TrackingResult
    {
        $seed = crc32($trackingNumber);
        $delivered = $seed % 3 === 0;

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            state: $delivered ? 'delivered' : 'in_transit',
            carrier: 'Demo Carrier',
            deliveredAt: $delivered ? Carbon::now()->subDays(1) : null,
            deliveryEvidence: $delivered ? 'Demo tracking feed reported delivery' : null,
            events: [
                ['at' => Carbon::now()->subDays(4)->toDateTimeString(), 'status' => 'Label created', 'location' => 'US-NJ'],
                ['at' => Carbon::now()->subDays(2)->toDateTimeString(), 'status' => 'In transit', 'location' => 'US'],
            ],
            raw: ['demo' => true],
        );
    }

    public function capabilities(): array
    {
        return [
            'catalogue' => true,
            'stock_by_warehouse' => true,
            'shipping_quotes' => true,
            'order_creation' => true,
            'order_lookup_by_our_reference' => true,
            'supplier_payment' => true,
            'tracking' => true,
            'packaging_selection' => false,
            'webhooks' => false,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function rememberOrder(SupplierOrderRequest $request, string $status): array
    {
        $existing = Cache::get(self::ORDER_CACHE_PREFIX.$request->internalReference);

        if (is_array($existing)) {
            return $existing;
        }

        $supplierOrderId = 'DEMO-'.strtoupper(substr(hash('sha256', $request->internalReference), 0, 12));

        $merchandise = 0;
        foreach ($request->lines as $line) {
            $merchandise += DemoCatalogue::costMinorFor($line['supplier_variant_id']) * $line['quantity'];
        }

        $stored = [
            'supplier_order_id' => $supplierOrderId,
            'supplier_order_number' => 'DEMO/'.substr($supplierOrderId, 5, 6),
            'internal_reference' => $request->internalReference,
            'status' => $status,
            'merchandise_minor' => $merchandise,
            'shipping_minor' => 599,
        ];

        Cache::put(self::ORDER_CACHE_PREFIX.$request->internalReference, $stored, now()->addDays(30));
        Cache::put(self::ORDER_CACHE_PREFIX.'id.'.$supplierOrderId, $request->internalReference, now()->addDays(30));

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    private function resultFrom(array $stored): SupplierOrderResult
    {
        return new SupplierOrderResult(
            supplierOrderId: (string) $stored['supplier_order_id'],
            supplierOrderNumber: (string) $stored['supplier_order_number'],
            status: (string) $stored['status'],
            merchandiseCost: Money::ofMinor((int) $stored['merchandise_minor'], 'USD'),
            shippingCost: Money::ofMinor((int) $stored['shipping_minor'], 'USD'),
            internalReference: (string) $stored['internal_reference'],
            raw: ['demo' => true] + $stored,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toProduct(array $row): SupplierProduct
    {
        $variants = [];

        foreach ($row['variants'] as $v) {
            $variants[] = new SupplierVariant(
                supplierVariantId: $v['vid'],
                sku: $v['sku'],
                name: $v['name'],
                cost: Money::ofMinor($v['cost_minor'], 'USD'),
                options: $v['options'],
                weightGrams: $v['weight_grams'],
                lengthMm: $v['length_mm'] ?? null,
                widthMm: $v['width_mm'] ?? null,
                heightMm: $v['height_mm'] ?? null,
                stock: DemoCatalogue::stockFor($v['vid']),
                images: $v['images'] ?? [],
                raw: ['demo' => true] + $v,
            );
        }

        return new SupplierProduct(
            supplierProductId: $row['pid'],
            name: $row['name'],
            description: $row['description'],
            categoryName: $row['category'],
            variants: $variants,
            images: $row['images'],
            raw: ['demo' => true] + $row,
        );
    }
}

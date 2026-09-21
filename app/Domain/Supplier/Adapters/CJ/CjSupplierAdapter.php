<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Adapters\CJ;

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
use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Exceptions\SupplierOperationUnsupported;
use App\Domain\Supplier\Exceptions\SupplierRequestFailed;
use App\Models\ShippingQuote;
use App\Models\Supplier;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Live CJdropshipping adapter.
 *
 * IMPORTANT — VERIFICATION STATUS
 * The endpoint paths and response field names here were written from CJ's
 * published API 2.0 conventions. The official reference at
 * developers.cjdropshipping.com was NOT reachable from the build
 * environment, so none of it has been exercised against the live API.
 * Every path lives in config/petstore.php so a correction is a config
 * change. Run `php artisan cj:verify` against a real account and work
 * through docs/integration-notes.md before switching mode to `live`.
 */
class CjSupplierAdapter implements SupplierAdapter
{
    public function __construct(
        private readonly Supplier $supplier,
        private readonly CjClient $client,
    ) {}

    public function supplier(): Supplier
    {
        return $this->supplier;
    }

    public function mode(): string
    {
        return $this->supplier->mode;
    }

    public function isDemo(): bool
    {
        return false;
    }

    public function authenticate(): AuthResult
    {
        try {
            $token = $this->client->authenticate(force: true);

            return new AuthResult(true, 'Authenticated with CJdropshipping.', $token['expires_at'] ?? null);
        } catch (SupplierException $e) {
            return new AuthResult(false, $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Catalogue                                                           */
    /* ------------------------------------------------------------------ */

    public function searchProducts(ProductSearchQuery $query): ProductSearchResult
    {
        $payload = array_filter([
            'pageNum' => $query->page,
            'pageSize' => $query->pageSize,
            'productNameEn' => $query->keyword,
            'categoryId' => $query->categoryId,
            // CJ exposes a warehouse/country filter on the list endpoint under
            // several names across revisions; send the documented one and
            // filter again locally so the result is correct either way.
            'countryCode' => $query->warehouseCountries[0] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $data = $this->client->get('product_list', $payload);

        $rows = $data['list'] ?? $data['content'] ?? [];
        $products = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $products[] = $this->mapListRow($row);
        }

        $total = isset($data['total']) ? (int) $data['total'] : null;

        return new ProductSearchResult(
            products: $products,
            page: $query->page,
            pageSize: $query->pageSize,
            totalCount: $total,
            hasMore: $total !== null
                ? ($query->page * $query->pageSize) < $total
                : count($products) >= $query->pageSize,
        );
    }

    public function fetchProduct(string $supplierProductId): ?SupplierProduct
    {
        try {
            $data = $this->client->get('product_detail', ['pid' => $supplierProductId]);
        } catch (SupplierRequestFailed $e) {
            // A product that has been removed upstream is a normal outcome,
            // not an error the operator needs to act on.
            if (str_contains(strtolower($e->getMessage()), 'not exist') || $e->statusCode === 404) {
                return null;
            }

            throw $e;
        }

        if ($data === [] || blank($data['pid'] ?? null)) {
            return null;
        }

        return $this->mapProductDetail($data);
    }

    public function fetchStock(array $supplierVariantIds): array
    {
        $out = [];

        foreach ($supplierVariantIds as $vid) {
            try {
                $data = $this->client->get('stock_by_variant', ['vid' => $vid]);
            } catch (SupplierRequestFailed) {
                // A failed reading is "unknown", never "zero".
                $out[$vid] = [];

                continue;
            }

            $rows = $data['list'] ?? (array_is_list($data) ? $data : []);
            $readings = [];

            foreach (is_array($rows) ? $rows : [] as $row) {
                $quantity = $row['storageNum'] ?? $row['stockNum'] ?? $row['quantity'] ?? null;

                $readings[] = new WarehouseStockReading(
                    warehouseCode: (string) ($row['areaEn'] ?? $row['countryCode'] ?? $row['storageName'] ?? 'UNKNOWN'),
                    countryCode: strtoupper((string) ($row['countryCode'] ?? substr((string) ($row['areaEn'] ?? ''), 0, 2))),
                    // Only a genuine numeric reading counts as known.
                    quantity: is_numeric($quantity) ? (int) $quantity : null,
                    warehouseName: $row['storageName'] ?? $row['areaEn'] ?? null,
                    raw: $row,
                );
            }

            $out[$vid] = $readings;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Shipping                                                            */
    /* ------------------------------------------------------------------ */

    public function quoteShipping(ShippingQuoteRequest $request): array
    {
        $payload = array_filter([
            'startCountryCode' => $request->warehouseCode ? substr($request->warehouseCode, 0, 2) : null,
            'endCountryCode' => strtoupper($request->destinationCountry),
            'zip' => $request->destinationPostalCode,
            'city' => $request->destinationCity,
            'province' => $request->destinationState,
            'houseNumber' => $request->houseNumber,
            'products' => array_map(fn (array $line) => [
                'vid' => $line['supplier_variant_id'],
                'quantity' => $line['quantity'],
            ], $request->lines),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $data = $this->client->post('freight_calculate', $payload);

        $rows = array_is_list($data) ? $data : ($data['list'] ?? $data['value'] ?? []);
        $options = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $options[] = $this->mapFreightRow($row, $request->warehouseCode);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapFreightRow(array $row, ?string $warehouseCode): SupplierShippingOption
    {
        $cost = $row['logisticPrice'] ?? $row['freightPrice'] ?? $row['price'] ?? null;
        $currency = strtoupper((string) ($row['logisticPriceCurrency'] ?? $row['currency'] ?? 'USD'));

        [$min, $max, $unit] = $this->parseAgeing($row['logisticAging'] ?? $row['aging'] ?? null);

        return new SupplierShippingOption(
            serviceName: (string) ($row['logisticName'] ?? $row['logisticNameEn'] ?? 'Standard shipping'),
            serviceCode: isset($row['logisticCode']) ? (string) $row['logisticCode'] : null,
            cost: is_numeric($cost)
                ? Money::ofDecimalString((string) $cost, $currency)
                : Money::zero($currency),
            estimateMin: $min,
            estimateMax: $max,
            estimateUnit: $unit,
            // CJ's ageing field is transit time. It does NOT include the
            // warehouse handling time, which we add separately and label.
            estimateType: $min === null ? ShippingQuote::TYPE_UNKNOWN : ShippingQuote::TYPE_TRANSIT,
            warehouseCode: $warehouseCode,
            raw: $row,
        );
    }

    /**
     * Parse an ageing string such as "7-15" or "10".
     *
     * CJ does not consistently state whether these are calendar or business
     * days. We therefore return `days` ONLY when the payload says so, and
     * `unknown` otherwise — we never guess the missing unit.
     *
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    private function parseAgeing(mixed $ageing): array
    {
        if (is_numeric($ageing)) {
            return [(int) $ageing, (int) $ageing, ShippingQuote::UNIT_UNKNOWN];
        }

        if (! is_string($ageing) || trim($ageing) === '') {
            return [null, null, ShippingQuote::UNIT_UNKNOWN];
        }

        $normalised = strtolower(trim($ageing));

        $unit = ShippingQuote::UNIT_UNKNOWN;
        if (str_contains($normalised, 'business') || str_contains($normalised, 'working')) {
            $unit = ShippingQuote::UNIT_BUSINESS_DAYS;
        } elseif (str_contains($normalised, 'day')) {
            $unit = ShippingQuote::UNIT_DAYS;
        }

        if (preg_match('/(\d+)\s*[-–~到]\s*(\d+)/u', $normalised, $m)) {
            return [(int) $m[1], (int) $m[2], $unit];
        }

        if (preg_match('/(\d+)/', $normalised, $m)) {
            return [(int) $m[1], (int) $m[1], $unit];
        }

        return [null, null, ShippingQuote::UNIT_UNKNOWN];
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    public function createOrder(SupplierOrderRequest $request): SupplierOrderResult
    {
        $address = $request->shippingAddress;

        $payload = array_filter([
            // Our reference travels with the order so a timed-out create can
            // be reconciled instead of retried.
            'orderNumber' => $request->internalReference,
            'shippingZip' => $address['postal_code'] ?? null,
            'shippingCountryCode' => strtoupper((string) ($address['country_code'] ?? 'US')),
            'shippingProvince' => $address['state'] ?? null,
            'shippingCity' => $address['city'] ?? null,
            'shippingAddress' => trim(($address['line1'] ?? '').' '.($address['line2'] ?? '')),
            'shippingCustomerName' => trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? '')),
            'shippingPhone' => $address['phone'] ?? null,
            'remark' => $request->note,
            'logisticName' => $request->shippingServiceCode,
            'fromCountryCode' => $request->warehouseCode ? substr($request->warehouseCode, 0, 2) : null,
            'products' => array_map(fn (array $line) => [
                'vid' => $line['supplier_variant_id'],
                'quantity' => $line['quantity'],
            ], $request->lines),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        if ($request->packagingId !== null) {
            if (! $this->supplier->supports('packaging_selection')) {
                throw new SupplierOperationUnsupported(
                    'packaging_selection',
                    'Branded packaging selection has not been verified as supported by the CJ API for this account.',
                    manualWorkaround: 'Arrange the packaging with your CJ agent and record the arrangement against the packaging record.',
                );
            }

            $payload['packingId'] = $request->packagingId;
        }

        $data = $this->client->post('order_create', $payload);

        $supplierOrderId = (string) ($data['orderId'] ?? $data['orderNum'] ?? '');

        if ($supplierOrderId === '') {
            throw new SupplierRequestFailed(
                'CJdropshipping accepted the order but returned no order id.',
                endpoint: 'order_create',
                outcomeUnknown: true,
            );
        }

        return new SupplierOrderResult(
            supplierOrderId: $supplierOrderId,
            supplierOrderNumber: isset($data['orderNum']) ? (string) $data['orderNum'] : null,
            status: (string) ($data['orderStatus'] ?? 'created'),
            merchandiseCost: $this->moneyOrNull($data['productAmount'] ?? null, $data['currency'] ?? 'USD'),
            shippingCost: $this->moneyOrNull($data['postageAmount'] ?? null, $data['currency'] ?? 'USD'),
            internalReference: $request->internalReference,
            raw: CjClient::redact($data),
        );
    }

    public function findOrderByInternalReference(string $internalReference): ?SupplierOrderResult
    {
        try {
            $data = $this->client->get('order_list', [
                'orderNumber' => $internalReference,
                'pageNum' => 1,
                'pageSize' => 20,
            ]);
        } catch (SupplierRequestFailed) {
            return null;
        }

        $rows = $data['list'] ?? $data['content'] ?? [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            // Only accept an exact match on OUR reference. A fuzzy match here
            // could attach an unrelated supplier order to a customer order.
            $candidates = [$row['orderNumber'] ?? null, $row['cjOrderNumber'] ?? null];

            if (! in_array($internalReference, array_map('strval', array_filter($candidates)), true)) {
                continue;
            }

            return new SupplierOrderResult(
                supplierOrderId: (string) ($row['orderId'] ?? ''),
                supplierOrderNumber: isset($row['orderNum']) ? (string) $row['orderNum'] : null,
                status: (string) ($row['orderStatus'] ?? 'unknown'),
                merchandiseCost: $this->moneyOrNull($row['productAmount'] ?? null, $row['currency'] ?? 'USD'),
                shippingCost: $this->moneyOrNull($row['postageAmount'] ?? null, $row['currency'] ?? 'USD'),
                internalReference: $internalReference,
                raw: CjClient::redact($row),
            );
        }

        return null;
    }

    public function getOrder(string $supplierOrderId): ?SupplierOrderResult
    {
        try {
            $data = $this->client->get('order_detail', ['orderId' => $supplierOrderId]);
        } catch (SupplierRequestFailed) {
            return null;
        }

        if ($data === []) {
            return null;
        }

        return new SupplierOrderResult(
            supplierOrderId: $supplierOrderId,
            supplierOrderNumber: isset($data['orderNum']) ? (string) $data['orderNum'] : null,
            status: (string) ($data['orderStatus'] ?? 'unknown'),
            merchandiseCost: $this->moneyOrNull($data['productAmount'] ?? null, $data['currency'] ?? 'USD'),
            shippingCost: $this->moneyOrNull($data['postageAmount'] ?? null, $data['currency'] ?? 'USD'),
            internalReference: isset($data['orderNumber']) ? (string) $data['orderNumber'] : null,
            raw: CjClient::redact($data),
        );
    }

    public function payOrder(string $supplierOrderId, Money $expectedAmount): SupplierPaymentResult
    {
        // Re-read the order and confirm the amount before authorising a charge.
        $current = $this->getOrder($supplierOrderId);

        if ($current === null) {
            return new SupplierPaymentResult(
                paid: false,
                status: 'unknown_order',
                failureReason: 'The supplier order could not be read back before payment. Reconcile before retrying.',
            );
        }

        $data = $this->client->post('pay_balance', ['orderId' => $supplierOrderId]);

        $status = (string) ($data['status'] ?? $data['payStatus'] ?? 'unknown');
        $paid = in_array(strtolower($status), ['1', 'success', 'paid', 'true'], true) || ($data['result'] ?? false) === true;

        return new SupplierPaymentResult(
            paid: $paid,
            status: $status,
            amount: $this->moneyOrNull($data['amount'] ?? null, $data['currency'] ?? $expectedAmount->currency),
            reference: isset($data['payId']) ? (string) $data['payId'] : null,
            failureReason: $paid ? null : (string) ($data['message'] ?? 'Supplier did not confirm payment.'),
            raw: CjClient::redact($data),
        );
    }

    public function getBalance(): ?Money
    {
        try {
            $data = $this->client->get('balance');
        } catch (SupplierException) {
            return null;
        }

        $amount = $data['amount'] ?? $data['balance'] ?? null;

        return $this->moneyOrNull($amount, $data['currency'] ?? 'USD');
    }

    public function trackShipment(string $trackingNumber): ?TrackingResult
    {
        try {
            $data = $this->client->get('track_info', ['trackNumber' => $trackingNumber]);
        } catch (SupplierException) {
            return null;
        }

        $events = [];
        $rows = $data['trackList'] ?? $data['list'] ?? [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $events[] = [
                'at' => (string) ($row['date'] ?? $row['time'] ?? ''),
                'status' => (string) ($row['status'] ?? $row['content'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
            ];
        }

        $state = $this->mapTrackingState((string) ($data['trackStatus'] ?? $data['status'] ?? ''));
        $deliveredAt = null;

        if ($state === 'delivered') {
            $last = end($events) ?: null;
            $deliveredAt = $this->parseDate($last['at'] ?? null);
        }

        return new TrackingResult(
            trackingNumber: $trackingNumber,
            state: $state,
            carrier: isset($data['logisticName']) ? (string) $data['logisticName'] : null,
            deliveredAt: $deliveredAt,
            // Delivery is only claimed when the carrier feed actually said so.
            deliveryEvidence: $state === 'delivered' ? 'CJ tracking feed reported delivery' : null,
            events: $events,
            raw: CjClient::redact($data),
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
            // Not claimed until observed against the real account.
            'packaging_selection' => $this->supplier->supports('packaging_selection'),
            'webhooks' => $this->supplier->supports('webhooks'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Mapping helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapListRow(array $row): SupplierProduct
    {
        return new SupplierProduct(
            supplierProductId: (string) ($row['pid'] ?? $row['productId'] ?? ''),
            name: (string) ($row['productNameEn'] ?? $row['productName'] ?? 'Untitled product'),
            description: $row['description'] ?? null,
            categoryName: $row['categoryName'] ?? null,
            variants: [],
            images: $this->extractImages($row),
            raw: $row,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function mapProductDetail(array $data): SupplierProduct
    {
        $variants = [];

        foreach ((array) ($data['variants'] ?? []) as $v) {
            if (! is_array($v)) {
                continue;
            }

            $currency = strtoupper((string) ($v['currency'] ?? 'USD'));
            $cost = $v['variantSellPrice'] ?? $v['sellPrice'] ?? $v['variantPrice'] ?? null;

            $variants[] = new SupplierVariant(
                supplierVariantId: (string) ($v['vid'] ?? ''),
                sku: isset($v['variantSku']) ? (string) $v['variantSku'] : null,
                name: isset($v['variantNameEn']) ? (string) $v['variantNameEn'] : null,
                cost: is_numeric($cost) ? Money::ofDecimalString((string) $cost, $currency) : null,
                options: $this->parseVariantOptions($v),
                weightGrams: is_numeric($v['variantWeight'] ?? null) ? (int) round((float) $v['variantWeight']) : null,
                lengthMm: $this->cmToMm($v['variantLength'] ?? null),
                widthMm: $this->cmToMm($v['variantWidth'] ?? null),
                heightMm: $this->cmToMm($v['variantHeight'] ?? null),
                stock: [],
                images: array_filter([$v['variantImage'] ?? null]),
                raw: $v,
            );
        }

        return new SupplierProduct(
            supplierProductId: (string) ($data['pid'] ?? ''),
            name: (string) ($data['productNameEn'] ?? $data['productName'] ?? 'Untitled product'),
            description: $data['description'] ?? null,
            categoryName: $data['categoryName'] ?? null,
            variants: $variants,
            images: $this->extractImages($data),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $variant
     * @return array<string, string>
     */
    private function parseVariantOptions(array $variant): array
    {
        $key = $variant['variantKey'] ?? $variant['variantNameEn'] ?? null;

        if (! is_string($key) || trim($key) === '') {
            return [];
        }

        $parts = preg_split('/\s*-\s*/', trim($key)) ?: [];
        $options = [];

        foreach ($parts as $index => $part) {
            if (trim($part) === '') {
                continue;
            }
            $options['Option '.($index + 1)] = trim($part);
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function extractImages(array $row): array
    {
        $images = $row['productImageSet'] ?? $row['productImage'] ?? [];

        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }

        return array_values(array_filter(
            array_map(fn ($i) => is_string($i) ? $i : null, (array) $images)
        ));
    }

    private function cmToMm(mixed $cm): ?int
    {
        return is_numeric($cm) ? (int) round((float) $cm * 10) : null;
    }

    private function moneyOrNull(mixed $amount, mixed $currency): ?Money
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return Money::ofDecimalString((string) $amount, strtoupper((string) ($currency ?: 'USD')));
    }

    private function mapTrackingState(string $status): string
    {
        $status = strtolower($status);

        return match (true) {
            str_contains($status, 'deliver') && ! str_contains($status, 'out for') => 'delivered',
            str_contains($status, 'out for') => 'out_for_delivery',
            str_contains($status, 'transit') || str_contains($status, 'shipped') => 'in_transit',
            str_contains($status, 'return') => 'returned',
            str_contains($status, 'exception') || str_contains($status, 'fail') => 'exception',
            default => 'pending',
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Contracts;

use App\Domain\Supplier\DTO\AuthResult;
use App\Domain\Supplier\DTO\ProductSearchQuery;
use App\Domain\Supplier\DTO\ProductSearchResult;
use App\Domain\Supplier\DTO\ShippingQuoteRequest;
use App\Domain\Supplier\DTO\SupplierOrderRequest;
use App\Domain\Supplier\DTO\SupplierOrderResult;
use App\Domain\Supplier\DTO\SupplierPaymentResult;
use App\Domain\Supplier\DTO\SupplierProduct;
use App\Domain\Supplier\DTO\TrackingResult;
use App\Domain\Supplier\Exceptions\SupplierOperationUnsupported;
use App\Models\Supplier;
use App\Support\Money\Money;

/**
 * The contract every supplier integration implements.
 *
 * Adding a second supplier later means writing another implementation of
 * this interface; nothing above it needs to change.
 *
 * Any operation a supplier genuinely cannot perform must throw
 * {@see SupplierOperationUnsupported} so the caller can present an honest
 * manual workflow. Returning a fabricated success is never acceptable.
 */
interface SupplierAdapter
{
    public function supplier(): Supplier;

    /** demo|sandbox|live */
    public function mode(): string;

    public function isDemo(): bool;

    /**
     * Verify credentials and refresh the stored token if needed.
     */
    public function authenticate(): AuthResult;

    public function searchProducts(ProductSearchQuery $query): ProductSearchResult;

    public function fetchProduct(string $supplierProductId): ?SupplierProduct;

    /**
     * Per-warehouse stock for the given supplier variant ids.
     *
     * @param  array<int, string>  $supplierVariantIds
     * @return array<string, array<int, \App\Domain\Supplier\DTO\WarehouseStockReading>>
     *         keyed by supplier variant id
     */
    public function fetchStock(array $supplierVariantIds): array;

    /**
     * @return array<int, \App\Domain\Supplier\DTO\SupplierShippingOption>
     */
    public function quoteShipping(ShippingQuoteRequest $request): array;

    public function createOrder(SupplierOrderRequest $request): SupplierOrderResult;

    /**
     * Look an order up by OUR reference. This is what makes a timed-out
     * create safe to resolve without risking a duplicate purchase.
     */
    public function findOrderByInternalReference(string $internalReference): ?SupplierOrderResult;

    public function getOrder(string $supplierOrderId): ?SupplierOrderResult;

    /**
     * Pay the supplier for an already-created order.
     *
     * @param  Money  $expectedAmount  Rechecked against the supplier's figure
     *                                 before any charge is authorised.
     */
    public function payOrder(string $supplierOrderId, Money $expectedAmount): SupplierPaymentResult;

    public function getBalance(): ?Money;

    public function trackShipment(string $trackingNumber): ?TrackingResult;

    /**
     * Capabilities this adapter exposes, e.g. ['packaging_selection' => false].
     *
     * @return array<string, bool>
     */
    public function capabilities(): array;
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalogue\CatalogueImporter;
use App\Domain\Catalogue\CatalogueSyncPolicy;
use App\Domain\Catalogue\StockSyncService;
use App\Domain\Checkout\CartService;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Fulfilment\FulfilmentService;
use App\Domain\Fulfilment\PreflightChecker;
use App\Domain\Fulfilment\SupplierPaymentService;
use App\Domain\Orders\OrderStateMachine;
use App\Domain\Payments\PaymentGatewayRegistry;
use App\Domain\Payments\PaymentService;
use App\Domain\Pricing\PriceRounder;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Returns\RefundService;
use App\Domain\Returns\ReturnService;
use App\Domain\Shipping\DeliveryEstimatePresenter;
use App\Domain\Shipping\ShippingQuoteService;
use App\Domain\Shipping\WarehouseSelector;
use App\Domain\Supplier\Services\SupplierRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the domain services.
 *
 * Everything is a singleton because these are stateless collaborators, and
 * the two registries cache resolved adapters for the request.
 */
class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Supplier
        $this->app->singleton(SupplierRegistry::class);
        $this->app->singleton(CatalogueSyncPolicy::class);
        $this->app->singleton(CatalogueImporter::class);
        $this->app->singleton(StockSyncService::class);

        // Pricing
        $this->app->singleton(PriceRounder::class);
        $this->app->singleton(PricingEngine::class);

        // Shipping
        $this->app->singleton(DeliveryEstimatePresenter::class);
        $this->app->singleton(WarehouseSelector::class);
        $this->app->singleton(ShippingQuoteService::class);

        // Orders / checkout
        $this->app->singleton(OrderStateMachine::class);
        $this->app->singleton(CartService::class);
        $this->app->singleton(CheckoutService::class);

        // Payments
        $this->app->singleton(PaymentGatewayRegistry::class);
        $this->app->singleton(PaymentService::class);

        // Fulfilment
        $this->app->singleton(PreflightChecker::class);
        $this->app->singleton(FulfilmentService::class);
        $this->app->singleton(SupplierPaymentService::class);

        // Returns
        $this->app->singleton(RefundService::class);
        $this->app->singleton(ReturnService::class);
    }
}

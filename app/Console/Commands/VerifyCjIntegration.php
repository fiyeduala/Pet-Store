<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Supplier\DTO\ProductSearchQuery;
use App\Domain\Supplier\DTO\ShippingQuoteRequest;
use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Supplier;
use Illuminate\Console\Command;
use Throwable;

/**
 * A REAL connectivity check against the supplier account.
 *
 * This is deliberately separate from the automated test suite, which mocks
 * every external call. Mocked tests prove our code handles a shape of
 * response; only this command proves the live account actually behaves that
 * way. Nothing here places an order, pays for anything, or changes supplier
 * data — it is read-only by construction.
 *
 * Run it before switching the integration to live mode, and record the
 * results in docs/integration-notes.md.
 */
class VerifyCjIntegration extends Command
{
    protected $signature = 'cj:verify
                            {--zip=07101 : A US ZIP code to quote shipping to}
                            {--save-capabilities : Record what worked on the supplier record}';

    protected $description = 'Read-only check of the CJdropshipping integration against the configured account.';

    public function handle(SupplierRegistry $registry): int
    {
        $supplier = Supplier::query()->where('code', 'cjdropshipping')->first();

        if ($supplier === null) {
            $this->error('No CJdropshipping supplier record exists. Run the database seeder first.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info("Integration mode: {$supplier->mode}");

        if ($supplier->isDemo()) {
            $this->warn('This supplier is in DEMO mode, so this exercises the demo adapter, not the real API.');
            $this->warn('Set the mode to sandbox or live in Admin → Integrations to test the real account.');
        }

        $adapter = $registry->for($supplier);
        $results = [];

        /* 1. Authentication */
        $auth = $adapter->authenticate();
        $results['authentication'] = $auth->ok;
        $this->result('Authentication', $auth->ok, $auth->message);

        if (! $auth->ok) {
            $this->line('');
            $this->error('Cannot continue without authentication.');

            return self::FAILURE;
        }

        /* 2. Product search (read-only) */
        $firstVariantId = null;

        $results['catalogue'] = $this->attempt('Product search', function () use ($adapter, &$firstVariantId) {
            $page = $adapter->searchProducts(new ProductSearchQuery(pageSize: 5));

            if ($page->products === []) {
                return 'Authenticated, but the search returned no products.';
            }

            $product = $adapter->fetchProduct($page->products[0]->supplierProductId);
            $firstVariantId = $product?->variants[0]->supplierVariantId ?? null;

            return sprintf(
                '%d product(s) returned; first has %d variant(s).',
                count($page->products),
                count($product?->variants ?? [])
            );
        });

        /* 3. Stock by warehouse */
        $results['stock_by_warehouse'] = $this->attempt('Warehouse stock', function () use ($adapter, $firstVariantId) {
            if ($firstVariantId === null) {
                throw new SupplierException('No variant id available from the catalogue step.');
            }

            $readings = $adapter->fetchStock([$firstVariantId])[$firstVariantId] ?? [];

            if ($readings === []) {
                return 'No warehouse rows returned. Stock will be treated as unknown, not as zero.';
            }

            $known = array_filter($readings, fn ($r) => $r->isKnown());

            return sprintf(
                '%d warehouse row(s), %d with an actual quantity. Countries: %s.',
                count($readings),
                count($known),
                implode(', ', array_unique(array_map(fn ($r) => $r->countryCode, $readings))) ?: 'none reported'
            );
        });

        /* 4. Shipping quotation */
        $results['shipping_quotes'] = $this->attempt('Shipping quotation', function () use ($adapter, $firstVariantId) {
            if ($firstVariantId === null) {
                throw new SupplierException('No variant id available from the catalogue step.');
            }

            $options = $adapter->quoteShipping(new ShippingQuoteRequest(
                destinationCountry: 'US',
                destinationState: 'NJ',
                destinationPostalCode: (string) $this->option('zip'),
                destinationCity: 'Newark',
                lines: [['supplier_variant_id' => $firstVariantId, 'quantity' => 1]],
            ));

            if ($options === []) {
                return 'No services returned for this destination.';
            }

            $withEstimate = array_filter($options, fn ($o) => $o->hasEstimate());
            $units = array_unique(array_map(fn ($o) => $o->estimateUnit, $options));

            return sprintf(
                '%d service(s); %d published a delivery estimate. Units seen: %s.',
                count($options),
                count($withEstimate),
                implode(', ', $units)
            );
        });

        /* 5. Account balance (read-only) */
        $results['supplier_payment'] = $this->attempt('Account balance', function () use ($adapter) {
            $balance = $adapter->getBalance();

            return $balance === null
                ? 'The balance endpoint returned nothing usable.'
                : 'Balance readable: '.$balance->format().'.';
        });

        /* 6. Capabilities we deliberately do NOT assume */
        $this->line('');
        $this->comment('Not verifiable without placing a real order, so still unconfirmed:');
        $this->line('  • Order creation and the exact response fields');
        $this->line('  • Supplier payment actually debiting the balance');
        $this->line('  • Branded packaging selection on an order');
        $this->line('  • Webhook delivery and signature format');
        $this->line('  Work through docs/integration-notes.md with a single low-value live order.');

        if ($this->option('save-capabilities')) {
            $supplier->forceFill([
                'capabilities' => array_merge($supplier->capabilities ?? [], array_map(
                    fn ($v) => (bool) $v,
                    array_filter($results, fn ($v) => is_bool($v))
                )),
                'last_checked_at' => now(),
            ])->save();

            $this->line('');
            $this->info('Recorded the verified capabilities on the supplier record.');
        }

        $this->line('');

        $failed = count(array_filter($results, fn ($v) => $v === false));

        if ($failed > 0) {
            $this->warn("{$failed} check(s) did not pass. Do not switch to live mode until they do.");

            return self::FAILURE;
        }

        $this->info('All read-only checks passed.');

        return self::SUCCESS;
    }

    private function attempt(string $label, callable $callback): bool
    {
        try {
            $detail = $callback();
            $this->result($label, true, is_string($detail) ? $detail : null);

            return true;
        } catch (SupplierException|Throwable $e) {
            $this->result($label, false, $e->getMessage());

            return false;
        }
    }

    private function result(string $label, bool $ok, ?string $detail): void
    {
        $this->line(sprintf(
            '  <fg=%s>%s</> %-22s %s',
            $ok ? 'green' : 'red',
            $ok ? '✓' : '✗',
            $label,
            $detail ? "<fg=gray>{$detail}</>" : ''
        ));
    }
}

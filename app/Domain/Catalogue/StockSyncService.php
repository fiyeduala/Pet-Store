<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierSyncLog;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;

/**
 * Refreshes per-warehouse stock and supplier cost.
 *
 * Stock is stored per warehouse and never merged into one number, because
 * "20 in China" and "20 in New Jersey" mean entirely different things for a
 * US order. A warehouse that reports nothing is recorded as unknown with
 * `quantity_known = false`, and a reading past its freshness window is
 * treated as unknown again rather than being trusted indefinitely.
 */
class StockSyncService
{
    public function __construct(private readonly SupplierRegistry $suppliers) {}

    /**
     * @param  Collection<int, ProductVariant>  $variants
     */
    public function sync(Supplier $supplier, Collection $variants): SupplierSyncLog
    {
        $log = SupplierSyncLog::create([
            'supplier_id' => $supplier->id,
            'type' => 'stock',
            'status' => 'running',
            'mode' => $supplier->mode,
            'started_at' => now(),
        ]);

        $adapter = $this->suppliers->for($supplier);
        $warehouses = Warehouse::query()->where('supplier_id', $supplier->id)->get()->keyBy('code');

        $processed = 0;
        $failed = 0;
        $errors = [];

        // Chunked so one long run cannot exhaust memory, and so a partial
        // failure still records everything that did succeed.
        foreach ($variants->chunk(20) as $chunk) {
            $byVariantId = $chunk
                ->filter(fn (ProductVariant $v) => filled($v->supplier_variant_id))
                ->keyBy(fn (ProductVariant $v) => (string) $v->supplier_variant_id);

            if ($byVariantId->isEmpty()) {
                continue;
            }

            try {
                $readings = $adapter->fetchStock($byVariantId->keys()->all());
            } catch (SupplierException $e) {
                $failed += $byVariantId->count();
                $errors[] = $e->getMessage();

                continue;
            }

            foreach ($byVariantId as $supplierVariantId => $variant) {
                $variantReadings = $readings[$supplierVariantId] ?? null;

                if ($variantReadings === null) {
                    $failed++;

                    continue;
                }

                $this->applyReadings($supplier, $variant, $variantReadings, $warehouses);
                $processed++;
            }
        }

        $log->forceFill([
            'status' => match (true) {
                $failed === 0 => 'success',
                $processed === 0 => 'failed',
                default => 'partial',
            },
            'items_processed' => $processed,
            'items_failed' => $failed,
            'error' => $errors === [] ? null : implode(' | ', array_slice($errors, 0, 5)),
            'finished_at' => now(),
        ])->save();

        return $log->refresh();
    }

    /**
     * @param  array<int, \App\Domain\Supplier\DTO\WarehouseStockReading>  $readings
     * @param  Collection<string, Warehouse>  $warehouses
     */
    private function applyReadings(Supplier $supplier, ProductVariant $variant, array $readings, Collection $warehouses): void
    {
        $freshnessHours = (int) config('petstore.sync.stock_freshness_hours', 12);
        $seen = [];

        foreach ($readings as $reading) {
            $warehouse = $warehouses->get($reading->warehouseCode);

            if ($warehouse === null) {
                // Discovering a warehouse we have never seen is normal; record
                // it disabled so an administrator decides whether to use it.
                $warehouse = Warehouse::create([
                    'supplier_id' => $supplier->id,
                    'code' => $reading->warehouseCode,
                    'name' => $reading->warehouseName ?: $reading->warehouseCode,
                    'country_code' => $reading->countryCode ?: 'XX',
                    'is_enabled' => false,
                    'priority' => 500,
                    'notes' => 'Discovered automatically during a stock sync. Review before enabling.',
                ]);

                $warehouses->put($warehouse->code, $warehouse);
            }

            WarehouseStock::updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'product_variant_id' => $variant->id],
                [
                    'supplier_variant_id' => $variant->supplier_variant_id,
                    'quantity' => $reading->quantity,
                    // The distinction that matters: did they actually tell us?
                    'quantity_known' => $reading->isKnown(),
                    'synced_at' => now(),
                    'stale_after' => now()->addHours($freshnessHours),
                    'source_payload' => $reading->raw,
                ]
            );

            $seen[] = $warehouse->id;
        }

        // A warehouse that has stopped reporting becomes unknown, not zero.
        WarehouseStock::query()
            ->where('product_variant_id', $variant->id)
            ->whereNotIn('warehouse_id', $seen ?: [0])
            ->update(['quantity_known' => false, 'synced_at' => now()]);
    }
}

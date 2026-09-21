<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Catalogue\StockSyncService;
use App\Models\Heartbeat;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Background refresh of supplier stock.
 *
 * Runs on the database queue so no Redis is required on cPanel. Overlap is
 * prevented by a cache lock, because two concurrent syncs would both hit
 * the supplier's 1 req/sec limit and fight each other.
 */
class SyncSupplierStock implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    /**
     * @param  array<int, int>|null  $variantIds  null syncs everything mapped
     */
    public function __construct(
        public int $supplierId,
        public ?array $variantIds = null,
    ) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('supplier-stock-'.$this->supplierId))->expireAfter(1800)];
    }

    public function handle(StockSyncService $service): void
    {
        $supplier = Supplier::find($this->supplierId);

        if ($supplier === null || ! $supplier->is_enabled) {
            return;
        }

        $variants = ProductVariant::query()
            ->whereNotNull('supplier_variant_id')
            ->when($this->variantIds !== null, fn ($q) => $q->whereIn('id', $this->variantIds))
            ->whereHas('product', fn ($q) => $q->where('supplier_id', $supplier->id))
            ->get();

        if ($variants->isEmpty()) {
            Heartbeat::beat('supplier_stock_sync', 'ok', 'Nothing to sync.');

            return;
        }

        $log = $service->sync($supplier, $variants);

        Heartbeat::beat(
            'supplier_stock_sync',
            $log->status === 'failed' ? 'failed' : 'ok',
            sprintf('%d synced, %d failed.', $log->items_processed, $log->items_failed),
            ['sync_log_id' => $log->id],
        );
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}

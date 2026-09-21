<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Services;

use App\Domain\Supplier\Adapters\CJ\CjClient;
use App\Domain\Supplier\Adapters\CJ\CjSupplierAdapter;
use App\Domain\Supplier\Adapters\Demo\DemoSupplierAdapter;
use App\Domain\Supplier\Contracts\SupplierAdapter;
use App\Models\Supplier;
use RuntimeException;

/**
 * Resolves the adapter for a supplier based on its configured mode.
 *
 * The mode is read from the database, never inferred and never silently
 * downgraded: if a live integration fails, the caller sees the failure.
 * There is deliberately no "fall back to demo on error" path, because that
 * would turn a real outage into a fabricated success.
 */
class SupplierRegistry
{
    /** @var array<int, SupplierAdapter> */
    private array $resolved = [];

    public function for(Supplier $supplier): SupplierAdapter
    {
        return $this->resolved[$supplier->id] ??= $this->make($supplier);
    }

    public function byCode(string $code): SupplierAdapter
    {
        $supplier = Supplier::query()->where('code', $code)->firstOrFail();

        return $this->for($supplier);
    }

    public function cj(): SupplierAdapter
    {
        return $this->byCode('cjdropshipping');
    }

    private function make(Supplier $supplier): SupplierAdapter
    {
        if ($supplier->mode === Supplier::MODE_DEMO) {
            return new DemoSupplierAdapter($supplier);
        }

        return match ($supplier->adapter) {
            'cjdropshipping' => new CjSupplierAdapter($supplier, new CjClient($supplier)),
            default => throw new RuntimeException("No adapter is registered for [{$supplier->adapter}]."),
        };
    }

    /**
     * Drop cached adapters, e.g. after an admin changes a supplier's mode.
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}

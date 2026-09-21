<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyMinor;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'name', 'option_summary', 'barcode',
        'weight_grams', 'length_mm', 'width_mm', 'height_mm',
        'currency', 'supplier_cost_minor', 'supplier_cost_currency',
        'computed_price_minor', 'manual_price_minor', 'compare_at_price_minor',
        'sale_price_minor', 'sale_starts_at', 'sale_ends_at', 'applied_pricing_rule',
        'supplier_variant_id', 'supplier_sku', 'supplier_source', 'supplier_synced_at',
        'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'supplier_synced_at' => 'datetime',
            'supplier_source' => 'array',
            'computed_price_minor' => MoneyMinor::class.':currency',
            'manual_price_minor' => MoneyMinor::class.':currency',
            'compare_at_price_minor' => MoneyMinor::class.':currency',
            'sale_price_minor' => MoneyMinor::class.':currency',
            'supplier_cost_minor' => MoneyMinor::class.':supplier_cost_currency',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class);
    }

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    public function supplierOffers(): HasMany
    {
        return $this->hasMany(SupplierOffer::class);
    }

    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function marketAvailability(): HasMany
    {
        return $this->hasMany(MarketVariantAvailability::class);
    }

    public function packagingRecords()
    {
        return $this->belongsToMany(PackagingRecord::class, 'packaging_record_variant');
    }

    /**
     * The price a customer actually pays, in precedence order:
     *   active sale price > manual override > rule-computed price.
     *
     * A manual override always beats a pricing rule until it is cleared.
     */
    public function effectivePrice(): ?Money
    {
        $minor = $this->effectivePriceMinor();

        return $minor === null ? null : Money::ofMinor($minor, $this->currency);
    }

    public function effectivePriceMinor(): ?int
    {
        if ($this->saleIsActive() && $this->getRawOriginal('sale_price_minor') !== null) {
            return (int) $this->getRawOriginal('sale_price_minor');
        }

        $manual = $this->getRawOriginal('manual_price_minor');
        if ($manual !== null) {
            return (int) $manual;
        }

        $computed = $this->getRawOriginal('computed_price_minor');

        return $computed === null ? null : (int) $computed;
    }

    /**
     * The price struck through next to the effective price, when one applies.
     */
    public function displayCompareAtMinor(): ?int
    {
        $effective = $this->effectivePriceMinor();

        if ($effective === null) {
            return null;
        }

        if ($this->saleIsActive()) {
            $base = $this->getRawOriginal('manual_price_minor') ?? $this->getRawOriginal('computed_price_minor');
            if ($base !== null && (int) $base > $effective) {
                return (int) $base;
            }
        }

        $compare = $this->getRawOriginal('compare_at_price_minor');

        return ($compare !== null && (int) $compare > $effective) ? (int) $compare : null;
    }

    public function saleIsActive(): bool
    {
        if ($this->getRawOriginal('sale_price_minor') === null) {
            return false;
        }

        $now = now();

        return ! ($this->sale_starts_at && $this->sale_starts_at->isAfter($now))
            && ! ($this->sale_ends_at && $this->sale_ends_at->isBefore($now));
    }

    public function hasManualPriceOverride(): bool
    {
        return $this->getRawOriginal('manual_price_minor') !== null;
    }

    /**
     * Stock actually sellable from the given warehouses, after the configured
     * safety buffer. Warehouses that did not report a number contribute zero:
     * unknown stock is never treated as unlimited stock.
     *
     * @param  array<int, int>|null  $warehouseIds
     */
    public function availableStock(?array $warehouseIds = null, ?int $buffer = null): int
    {
        $buffer ??= (int) settings('inventory.stock_buffer', 0);

        return (int) $this->loadMissing('warehouseStocks')->warehouseStocks
            ->when($warehouseIds !== null, fn ($c) => $c->whereIn('warehouse_id', $warehouseIds))
            ->filter(fn (WarehouseStock $s) => $s->quantity_known && ! $s->isStale())
            ->sum(fn (WarehouseStock $s) => max(0, (int) $s->quantity - $buffer));
    }

    /**
     * True when at least one warehouse reported a number for this variant.
     * False means "we do not know", which is presented differently to "out of stock".
     */
    public function hasKnownStock(?array $warehouseIds = null): bool
    {
        return $this->loadMissing('warehouseStocks')->warehouseStocks
            ->when($warehouseIds !== null, fn ($c) => $c->whereIn('warehouse_id', $warehouseIds))
            ->contains(fn (WarehouseStock $s) => $s->quantity_known && ! $s->isStale());
    }

    public function isPurchasable(?array $warehouseIds = null): bool
    {
        return $this->is_active
            && $this->effectivePriceMinor() !== null
            && $this->availableStock($warehouseIds) > 0;
    }

    public function optionLabel(): string
    {
        return $this->option_summary ?: ($this->name ?: $this->sku);
    }

    public function displayName(): string
    {
        $base = $this->product?->name ?? $this->sku;

        return $this->option_summary ? "{$base} — {$this->option_summary}" : $base;
    }

    public function billableWeightGrams(): int
    {
        return $this->weight_grams ?: (int) settings('shipping.default_weight_grams', 500);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Supplier\DTO\SupplierProduct;
use App\Domain\Supplier\DTO\SupplierVariant;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports a chosen supplier product into the catalogue as a DRAFT.
 *
 * Nothing is ever published automatically. The whole supplier catalogue is
 * never bulk-imported: an administrator picks specific products, edits the
 * merchandising, and publishes deliberately.
 */
class CatalogueImporter
{
    public function __construct(
        private readonly SupplierRegistry $suppliers,
        private readonly CatalogueSyncPolicy $policy,
    ) {}

    /**
     * @param  array<int, string>|null  $onlyVariantIds  import a subset of variants
     */
    public function import(Supplier $supplier, string $supplierProductId, ?array $onlyVariantIds = null): Product
    {
        $adapter = $this->suppliers->for($supplier);
        $source = $adapter->fetchProduct($supplierProductId);

        if ($source === null) {
            throw new RuntimeException("Supplier product [{$supplierProductId}] could not be found.");
        }

        return DB::transaction(function () use ($supplier, $source, $onlyVariantIds) {
            $product = Product::withTrashed()
                ->where('supplier_id', $supplier->id)
                ->where('supplier_product_id', $source->supplierProductId)
                ->first();

            if ($product === null) {
                $product = new Product([
                    'slug' => $this->uniqueSlug($source->name),
                    'name' => $source->name,
                    'description' => $source->description,
                    // Draft always. Publishing is an explicit admin action.
                    'status' => Product::STATUS_DRAFT,
                    'supplier_id' => $supplier->id,
                    'supplier_product_id' => $source->supplierProductId,
                ]);
            } elseif ($product->trashed()) {
                $product->restore();
            }

            // Source payload is preserved verbatim for auditing and for a
            // later re-map if the mapping logic changes.
            $product->forceFill([
                'supplier_source' => $source->raw,
                'supplier_synced_at' => now(),
            ]);

            $this->policy->applySupplierFields($product, [
                'name' => $source->name,
                'description' => $source->description,
            ]);

            $product->save();

            $this->importVariants($product, $supplier, $source, $onlyVariantIds);
            $this->importImages($product, $source);

            if ($product->default_variant_id === null) {
                $product->forceFill(['default_variant_id' => $product->variants()->first()?->id])->save();
            }

            return $product->refresh();
        });
    }

    /**
     * @param  array<int, string>|null  $onlyVariantIds
     */
    private function importVariants(Product $product, Supplier $supplier, SupplierProduct $source, ?array $onlyVariantIds): void
    {
        foreach ($source->variants as $index => $sv) {
            if ($onlyVariantIds !== null && ! in_array($sv->supplierVariantId, $onlyVariantIds, true)) {
                continue;
            }

            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('supplier_variant_id', $sv->supplierVariantId)
                ->first();

            if ($variant === null) {
                $variant = new ProductVariant([
                    'product_id' => $product->id,
                    'sku' => $this->uniqueSku($sv, $product),
                    'supplier_variant_id' => $sv->supplierVariantId,
                    'currency' => $product->supplier?->setting('currency', 'USD') ?? 'USD',
                ]);
            } elseif ($variant->trashed()) {
                $variant->restore();
            }

            $this->policy->applySupplierVariantFields($variant, $sv, $index);
            $variant->save();

            if ($sv->cost !== null) {
                SupplierOffer::updateOrCreate(
                    ['supplier_id' => $supplier->id, 'product_variant_id' => $variant->id],
                    [
                        'supplier_variant_id' => $sv->supplierVariantId,
                        'cost_minor' => $sv->cost->minor,
                        'currency' => $sv->cost->currency,
                        'is_available' => true,
                        'source_payload' => $sv->raw,
                        'synced_at' => now(),
                    ]
                );
            }
        }
    }

    private function importImages(Product $product, SupplierProduct $source): void
    {
        // Curated images are owner property and are never displaced by a
        // sync; supplier images are only added when nothing curated exists.
        if ($product->media()->where('is_curated', true)->exists()) {
            return;
        }

        foreach ($source->images as $position => $url) {
            if (! is_string($url) || $url === '') {
                continue;
            }

            $product->media()->firstOrCreate(
                ['source_url' => $url],
                [
                    'disk' => 'public',
                    'path' => $url,
                    'alt' => $product->name,
                    'position' => $position,
                    'is_curated' => false,
                ]
            );
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $i = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function uniqueSku(SupplierVariant $sv, Product $product): string
    {
        $base = Str::upper(Str::slug($sv->sku ?: ($product->slug.'-'.$sv->supplierVariantId)));
        $base = Str::limit($base, 48, '');
        $sku = $base;
        $i = 2;

        while (ProductVariant::withTrashed()->where('sku', $sku)->exists()) {
            $sku = $base.'-'.$i++;
        }

        return $sku;
    }
}

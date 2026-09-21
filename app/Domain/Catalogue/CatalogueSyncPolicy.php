<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Domain\Supplier\DTO\SupplierVariant;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Decides, field by field, whether a supplier sync may overwrite what is
 * already stored.
 *
 * The rule: once the owner has written something, the supplier does not get
 * to replace it. A field becomes owner-owned the moment it is edited in
 * admin (the resource locks it), and locked fields are listed on the
 * product so the decision is visible and reversible.
 *
 * Cost and stock are the exception — those are always the supplier's to
 * report, unless a manual retail price override is in force, which the
 * pricing engine honours separately.
 */
class CatalogueSyncPolicy
{
    /**
     * Fields a sync may write only while the owner has not claimed them.
     *
     * @param  array<string, mixed>  $values
     */
    public function applySupplierFields(Product $product, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (! in_array($field, Product::OWNER_OWNED_FIELDS, true)) {
                $product->{$field} = $value;

                continue;
            }

            // Only fill an owner-owned field while it is still empty and
            // unlocked; never overwrite existing owner copy.
            if ($product->fieldIsOwnerLocked($field)) {
                continue;
            }

            if (blank($product->{$field})) {
                $product->{$field} = $value;
            }
        }
    }

    public function applySupplierVariantFields(ProductVariant $variant, SupplierVariant $sv, int $position): void
    {
        // Physical facts always come from the supplier.
        $variant->weight_grams = $sv->weightGrams ?? $variant->weight_grams;
        $variant->length_mm = $sv->lengthMm ?? $variant->length_mm;
        $variant->width_mm = $sv->widthMm ?? $variant->width_mm;
        $variant->height_mm = $sv->heightMm ?? $variant->height_mm;
        $variant->supplier_sku = $sv->sku;
        $variant->supplier_source = $sv->raw;
        $variant->supplier_synced_at = now();

        if ($sv->cost !== null) {
            $variant->supplier_cost_minor = $sv->cost->minor;
            $variant->supplier_cost_currency = $sv->cost->currency;
        }

        // Naming is merchandising, so only filled in while still empty.
        if (blank($variant->name)) {
            $variant->name = $sv->name;
        }

        if (blank($variant->option_summary)) {
            $variant->option_summary = $sv->optionSummary();
        }

        if ($variant->position === 0) {
            $variant->position = $position;
        }
    }

    /**
     * Which product fields a sync would currently leave alone.
     *
     * @return array<int, string>
     */
    public function protectedFields(Product $product): array
    {
        return array_values(array_filter(
            Product::OWNER_OWNED_FIELDS,
            fn (string $field) => $product->fieldIsOwnerLocked($field) || filled($product->{$field})
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Catalogue;

use App\Models\Attribute;
use App\Models\Category;
use App\Models\Collection as ProductCollection;
use App\Models\PetType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Catalogue browsing: filtering, sorting and pagination.
 *
 * Every filter is expressed in the query string so a filtered view is
 * linkable, shareable and survives a page refresh or a back button.
 */
class ProductFilter
{
    public const SORTS = [
        'featured' => 'Featured',
        'newest' => 'Newest',
        'price_asc' => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name_asc' => 'Name: A to Z',
    ];

    public const PER_PAGE = 24;

    /**
     * @return array<string, mixed>
     */
    public function apply(
        Request $request,
        ?PetType $petType = null,
        ?Category $category = null,
        ?ProductCollection $collection = null,
    ): array {
        $query = Product::published()
            ->with(['media', 'variants' => fn ($q) => $q->where('is_active', true), 'variants.warehouseStocks']);

        if ($petType !== null) {
            $query->whereHas('petTypes', fn (Builder $q) => $q->where('pet_types.id', $petType->id));
        }

        if ($category !== null) {
            $query->whereHas('categories', fn (Builder $q) => $q->whereIn('categories.id', $category->selfAndDescendantIds()));
        }

        if ($collection !== null) {
            $query->whereHas('collections', fn (Builder $q) => $q->where('collections.id', $collection->id));
        }

        $this->applySearch($query, $request->string('q')->toString());
        $this->applyPetTypeFilter($query, $request);
        $this->applyCategoryFilter($query, $request);
        $this->applyPriceFilter($query, $request);
        $this->applyAvailabilityFilter($query, $request);
        $this->applyAttributeFilters($query, $request);
        $this->applySort($query, $request->string('sort')->toString());

        $products = $query
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return [
            'products' => $products,
            'sorts' => self::SORTS,
            'activeSort' => $this->resolveSort($request->string('sort')->toString()),
            'filterOptions' => $this->options($petType, $category),
            'activeFilters' => $this->activeFilters($request),
        ];
    }

    private function applySearch(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        $query->where(function (Builder $q) use ($like): void {
            $q->where('name', 'like', $like)
                ->orWhere('subtitle', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhereHas('variants', fn (Builder $v) => $v->where('sku', 'like', $like));
        });
    }

    private function applyPetTypeFilter(Builder $query, Request $request): void
    {
        $slugs = $this->arrayParam($request, 'pet');

        if ($slugs === []) {
            return;
        }

        $query->whereHas('petTypes', fn (Builder $q) => $q->whereIn('pet_types.slug', $slugs));
    }

    private function applyCategoryFilter(Builder $query, Request $request): void
    {
        $slugs = $this->arrayParam($request, 'category');

        if ($slugs === []) {
            return;
        }

        $ids = Category::query()->whereIn('slug', $slugs)->get()
            ->flatMap(fn (Category $c) => $c->selfAndDescendantIds())
            ->unique()
            ->all();

        $query->whereHas('categories', fn (Builder $q) => $q->whereIn('categories.id', $ids ?: [0]));
    }

    /**
     * Price bounds arrive in whole currency units and are converted to
     * minor units for the integer comparison.
     */
    private function applyPriceFilter(Builder $query, Request $request): void
    {
        $min = $request->filled('min_price') ? (int) round((float) $request->input('min_price') * 100) : null;
        $max = $request->filled('max_price') ? (int) round((float) $request->input('max_price') * 100) : null;

        if ($min === null && $max === null) {
            return;
        }

        $query->whereHas('variants', function (Builder $q) use ($min, $max): void {
            $q->where('is_active', true);

            // A manual override wins over the computed price, so the filter
            // must compare against whichever is actually in force.
            $effective = 'COALESCE(sale_price_minor, manual_price_minor, computed_price_minor)';

            if ($min !== null) {
                $q->whereRaw("{$effective} >= ?", [$min]);
            }

            if ($max !== null) {
                $q->whereRaw("{$effective} <= ?", [$max]);
            }
        });
    }

    private function applyAvailabilityFilter(Builder $query, Request $request): void
    {
        if ($request->input('availability') !== 'in_stock') {
            return;
        }

        // "In stock" means a warehouse actually reported a positive number.
        // Unknown availability is deliberately excluded from this filter.
        $query->whereHas('variants.warehouseStocks', function (Builder $q): void {
            $q->where('quantity_known', true)
                ->where('quantity', '>', 0)
                ->where(fn (Builder $s) => $s->whereNull('stale_after')->orWhere('stale_after', '>', now()));
        });
    }

    private function applyAttributeFilters(Builder $query, Request $request): void
    {
        $attributes = Attribute::filterable()->pluck('id', 'code');

        foreach ($attributes as $code => $attributeId) {
            $values = $this->arrayParam($request, 'attr_'.$code);

            if ($values === []) {
                continue;
            }

            $query->where(function (Builder $q) use ($attributeId, $values): void {
                $q->whereHas('attributeValues', fn (Builder $a) => $a
                    ->where('attribute_id', $attributeId)
                    ->whereHas('attributeValue', fn (Builder $v) => $v->whereIn('value', $values)))
                    ->orWhereHas('variants.attributeValues', fn (Builder $a) => $a
                        ->where('attribute_id', $attributeId)
                        ->whereHas('attributeValue', fn (Builder $v) => $v->whereIn('value', $values)));
            });
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        $effective = 'COALESCE(sale_price_minor, manual_price_minor, computed_price_minor)';

        match ($this->resolveSort($sort)) {
            'newest' => $query->latest('published_at')->latest('id'),
            'price_asc' => $query->orderByRaw(
                "(select min({$effective}) from product_variants where product_variants.product_id = products.id and product_variants.is_active = 1 and product_variants.deleted_at is null) asc"
            ),
            'price_desc' => $query->orderByRaw(
                "(select min({$effective}) from product_variants where product_variants.product_id = products.id and product_variants.is_active = 1 and product_variants.deleted_at is null) desc"
            ),
            'name_asc' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->latest('published_at')->latest('id'),
        };
    }

    private function resolveSort(string $sort): string
    {
        return array_key_exists($sort, self::SORTS) ? $sort : 'featured';
    }

    /**
     * @return array<string, mixed>
     */
    private function options(?PetType $petType, ?Category $category): array
    {
        return [
            'petTypes' => PetType::active()->orderBy('position')->get(),
            'categories' => $category !== null && $category->children->isNotEmpty()
                ? $category->children
                : Category::active()->whereNull('parent_id')->orderBy('position')->get(),
            'attributes' => Attribute::filterable()->with('values')->orderBy('position')->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activeFilters(Request $request): array
    {
        return array_filter([
            'q' => $request->string('q')->toString() ?: null,
            'pet' => $this->arrayParam($request, 'pet') ?: null,
            'category' => $this->arrayParam($request, 'category') ?: null,
            'min_price' => $request->input('min_price'),
            'max_price' => $request->input('max_price'),
            'availability' => $request->input('availability'),
        ]);
    }

    /**
     * Accept both `pet=dogs&pet=cats` and `pet=dogs,cats`.
     *
     * @return array<int, string>
     */
    private function arrayParam(Request $request, string $key): array
    {
        $value = $request->input($key);

        if ($value === null || $value === '') {
            return [];
        }

        $values = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $values)));
    }
}

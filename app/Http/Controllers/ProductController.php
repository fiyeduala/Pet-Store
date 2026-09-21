<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public function show(Product $product): View
    {
        abort_unless($product->isPublished(), 404);

        $product->load([
            'media',
            'variants' => fn ($q) => $q->where('is_active', true)->orderBy('position'),
            'variants.attributeValues.attribute',
            'variants.attributeValues.attributeValue',
            'variants.warehouseStocks.warehouse',
            'attributeValues.attribute',
            'attributeValues.attributeValue',
            'categories',
            'petTypes',
        ]);

        $related = Product::published()
            ->whereKeyNot($product->id)
            ->when($product->categories->isNotEmpty(), fn ($q) => $q->whereHas(
                'categories',
                fn ($c) => $c->whereIn('categories.id', $product->categories->pluck('id'))
            ))
            ->with(['media', 'variants.warehouseStocks'])
            ->limit(4)
            ->get();

        return view('storefront.product', [
            'product' => $product,
            'related' => $related,
            // Only genuine reviews are ever shown. Sample rows exist for
            // layout validation and are excluded here.
            'reviews' => $product->reviews()->genuine()->latest()->limit(10)->get(),
        ]);
    }
}

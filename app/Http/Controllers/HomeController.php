<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\HomeSection;
use App\Models\PetType;
use App\Models\Product;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $sections = HomeSection::active()->with('collection.products.variants')->get();

        return view('storefront.home', [
            'sections' => $sections,
            'petTypes' => PetType::active()->orderBy('position')->get(),
            'featured' => Product::published()
                ->where('is_featured', true)
                ->with(['media', 'variants.warehouseStocks'])
                ->limit(8)
                ->get(),
            'collections' => Collection::active()->where('is_featured', true)->orderBy('position')->get(),
        ]);
    }
}

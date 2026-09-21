<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Catalogue\ProductFilter;
use App\Models\Category;
use App\Models\Collection;
use App\Models\PetType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function __construct(private readonly ProductFilter $filter) {}

    public function index(Request $request): View
    {
        return view('storefront.shop', $this->filter->apply($request) + [
            'heading' => $request->filled('q') ? 'Results for “'.$request->string('q').'”' : 'All products',
            'subheading' => null,
            'context' => null,
        ]);
    }

    public function petType(Request $request, PetType $petType): View
    {
        abort_unless($petType->is_active, 404);

        return view('storefront.shop', $this->filter->apply($request, petType: $petType) + [
            'heading' => $petType->name,
            'subheading' => $petType->tagline ?: $petType->description,
            'context' => $petType,
        ]);
    }

    public function category(Request $request, Category $category): View
    {
        abort_unless($category->is_active, 404);

        return view('storefront.shop', $this->filter->apply($request, category: $category) + [
            'heading' => $category->name,
            'subheading' => $category->description,
            'context' => $category,
        ]);
    }

    public function collection(Request $request, Collection $collection): View
    {
        abort_unless($collection->is_active, 404);

        return view('storefront.shop', $this->filter->apply($request, collection: $collection) + [
            'heading' => $collection->title,
            'subheading' => $collection->subtitle ?: $collection->description,
            'context' => $collection,
        ]);
    }
}

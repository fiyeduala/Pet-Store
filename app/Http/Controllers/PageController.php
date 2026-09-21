<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Models\Faq;
use Illuminate\Contracts\View\View;

class PageController extends Controller
{
    public function show(ContentPage $page): View
    {
        abort_unless($page->is_published, 404);

        return view('storefront.page', ['page' => $page]);
    }

    public function faq(): View
    {
        return view('storefront.faq', [
            'groups' => Faq::active()->get()->groupBy('category'),
        ]);
    }
}

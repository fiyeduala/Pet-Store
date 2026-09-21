<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalogue\CatalogueImporter;
use App\Domain\Catalogue\StockSyncService;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Supplier\Adapters\Demo\DemoCatalogue;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Market;
use App\Models\PetType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sample merchandising, imported through the real supplier import path.
 *
 * This deliberately uses CatalogueImporter and StockSyncService rather than
 * writing rows directly, so the demo data exercises the same code a real
 * CJ import will, including the owner-lock sync policy.
 *
 * Never runs in production: see the guard in run().
 */
class DemoCatalogueSeeder extends Seeder
{
    /** Maps demo supplier products onto merchandising. */
    private const MERCHANDISING = [
        'DEMO-P-1001' => ['toys', ['dogs'], 'Rope that survives a real tug session', 'Cotton', 'Machine washable, air dry.', 'Medium and large dogs who like to pull', ['everyday-essentials'], true],
        'DEMO-P-1002' => ['feeding', ['dogs', 'cats'], 'Slows down a fast eater', 'Food-safe polypropylene', 'Dishwasher safe, top rack.', 'Dogs and cats who eat too quickly', ['everyday-essentials'], true],
        'DEMO-P-1003' => ['beds', ['dogs'], 'A proper bolster to lean into', 'Corduroy cover, recycled polyester fill', 'Cover is removable and machine washable at 30°C.', 'Dogs who sleep curled against an edge', ['quiet-corners'], true],
        'DEMO-P-1004' => ['enrichment', ['cats'], 'Sisal that lasts more than a fortnight', 'Natural sisal, engineered wood base', 'Vacuum loose fibres; replace when worn through.', 'Cats who scratch furniture', ['quiet-corners'], true],
        'DEMO-P-1005' => ['grooming', ['dogs', 'cats'], 'Gets the undercoat out', 'Stainless steel, moulded grip', 'Rinse and dry after use.', 'Double-coated dogs and long-haired cats', [], false],
        'DEMO-P-1006' => ['travel', ['dogs'], 'Water without carrying a bowl', 'BPA-free polypropylene', 'Hand wash. Do not freeze.', 'Walks, hikes and car journeys', ['everyday-essentials'], false],
        'DEMO-P-1007' => ['enrichment', ['dogs'], 'Adjustable difficulty as they get better at it', 'Food-safe TPR', 'Rinse after each use.', 'Dogs who finish a meal in thirty seconds', [], false],
        'DEMO-P-1008' => ['travel', ['dogs'], 'Reflective stitching, two attachment points', 'Recycled polyester webbing, padded mesh', 'Hand wash cold, air dry.', 'Dogs who pull, and evening walks', ['new-arrivals'], true],
    ];

    public function run(): void
    {
        // A production deploy must never seed fake catalogue data.
        if (app()->isProduction() && ! config('petstore.demo.seeding_enabled')) {
            throw new RuntimeException(
                'Refusing to seed demo catalogue data in production. Set DEMO_SEEDING_ENABLED=true only if you genuinely want this.'
            );
        }

        $this->publishPlaceholderImages();

        $supplier = Supplier::where('code', 'cjdropshipping')->firstOrFail();
        $market = Market::where('code', 'US')->firstOrFail();

        $importer = app(CatalogueImporter::class);
        $pricing = app(PricingEngine::class);

        foreach (DemoCatalogue::products() as $index => $row) {
            $product = $importer->import($supplier, $row['pid']);
            $this->merchandise($product, $row['pid'], $index);

            // Price every variant through the real pricing engine so the
            // seeded prices are the ones the rules actually produce.
            foreach ($product->variants as $variant) {
                $pricing->repriceVariant($variant, $market);
            }
        }

        // Populate per-warehouse stock through the real sync path.
        app(StockSyncService::class)->sync(
            $supplier,
            ProductVariant::whereNotNull('supplier_variant_id')->get()
        );

        $this->command?->info('Seeded '.Product::count().' demo products with per-warehouse stock.');
    }

    /**
     * Copy the tracked placeholder artwork into the public disk.
     *
     * The images live under database/seeders/assets so they are in version
     * control; storage/app/public is runtime state and is not. Without this
     * a fresh clone would seed products whose images 404.
     */
    private function publishPlaceholderImages(): void
    {
        $source = database_path('seeders/assets/demo');

        if (! File::isDirectory($source)) {
            $this->command?->warn('No placeholder images found; demo products will have no imagery.');

            return;
        }

        $disk = Storage::disk('public');

        foreach (File::files($source) as $file) {
            $target = 'demo/'.$file->getFilename();

            if (! $disk->exists($target)) {
                $disk->put($target, File::get($file->getPathname()));
            }
        }
    }

    private function merchandise(Product $product, string $pid, int $index): void
    {
        [$categorySlug, $petSlugs, $subtitle, $materials, $care, $suitableFor, $collectionSlugs, $featured]
            = self::MERCHANDISING[$pid];

        $product->forceFill([
            'subtitle' => $subtitle,
            'materials' => $materials,
            'care_instructions' => $care,
            'suitable_for' => $suitableFor,
            'brand' => 'Pet Store',
            'status' => Product::STATUS_PUBLISHED,
            'published_at' => now()->subDays(30 - $index),
            'is_featured' => $featured,
            'seo_description' => $subtitle,
            // Everything above is owner-written merchandising, so a future
            // supplier sync must not overwrite it.
            'locked_fields' => ['subtitle', 'materials', 'care_instructions', 'suitable_for', 'seo_description'],
        ])->save();

        if ($category = Category::where('slug', $categorySlug)->first()) {
            $product->categories()->syncWithoutDetaching([$category->id]);
        }

        $product->petTypes()->syncWithoutDetaching(
            PetType::whereIn('slug', $petSlugs)->pluck('id')->all()
        );

        if ($collectionSlugs !== []) {
            $product->collections()->syncWithoutDetaching(
                Collection::whereIn('slug', $collectionSlugs)->pluck('id')->all()
            );
        }
    }
}

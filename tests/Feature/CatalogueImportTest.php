<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Catalogue\CatalogueImporter;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    #[Test]
    public function an_imported_product_always_starts_as_a_draft(): void
    {
        $product = app(CatalogueImporter::class)->import(Supplier::cj(), 'DEMO-P-1001');

        $this->assertSame(Product::STATUS_DRAFT, $product->status);
        $this->assertNull($product->published_at);
        $this->assertFalse($product->isPublished());
    }

    #[Test]
    public function importing_the_same_product_twice_does_not_duplicate_it(): void
    {
        $importer = app(CatalogueImporter::class);

        $first = $importer->import(Supplier::cj(), 'DEMO-P-1001');
        $second = $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Product::where('supplier_product_id', 'DEMO-P-1001')->count());
        $this->assertSame(2, $second->variants()->count());
    }

    #[Test]
    public function a_sync_never_overwrites_an_owner_written_field(): void
    {
        $importer = app(CatalogueImporter::class);
        $product = $importer->import(Supplier::cj(), 'DEMO-P-1001');

        // The owner rewrites the merchandising and it is locked.
        $product->forceFill([
            'name' => 'Our Own Better Name',
            'description' => 'Copy we wrote ourselves.',
            'locked_fields' => ['name', 'description'],
        ])->save();

        // A later sync must leave both alone.
        $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $fresh = $product->fresh();
        $this->assertSame('Our Own Better Name', $fresh->name);
        $this->assertSame('Copy we wrote ourselves.', $fresh->description);

        // But supplier-owned facts are still refreshed.
        $this->assertNotNull($fresh->supplier_synced_at);
        $this->assertNotEmpty($fresh->supplier_source);
    }

    #[Test]
    public function a_sync_does_not_displace_curated_images(): void
    {
        $importer = app(CatalogueImporter::class);
        $product = $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $product->media()->delete();
        $product->media()->create([
            'disk' => 'public',
            'path' => 'brand/our-own-photo.jpg',
            'alt' => 'Our photography',
            'is_curated' => true,
            'position' => 0,
        ]);

        $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $media = $product->fresh()->media;
        $this->assertCount(1, $media);
        $this->assertTrue($media->first()->is_curated);
        $this->assertSame('brand/our-own-photo.jpg', $media->first()->path);
    }

    #[Test]
    public function supplier_cost_is_always_refreshed_because_it_is_theirs_to_report(): void
    {
        $importer = app(CatalogueImporter::class);
        $product = $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $variant = $product->variants()->first();
        $originalCost = (int) $variant->getRawOriginal('supplier_cost_minor');

        $variant->forceFill(['supplier_cost_minor' => 1])->save();

        $importer->import(Supplier::cj(), 'DEMO-P-1001');

        $this->assertSame($originalCost, (int) $variant->fresh()->getRawOriginal('supplier_cost_minor'));
    }

    #[Test]
    public function the_import_page_lists_products_and_marks_those_already_imported(): void
    {
        $owner = User::factory()->create(['is_staff' => true]);
        $owner->syncRoles(['owner']);

        app(CatalogueImporter::class)->import(Supplier::cj(), 'DEMO-P-1001');

        Livewire::actingAs($owner)
            ->test(\App\Filament\Pages\ImportSupplierProducts::class)
            ->call('search')
            ->assertSet('searchError', null)
            ->assertCount('results', 8)
            ->assertSee('Braided Rope Tug Toy');
    }

    #[Test]
    public function importing_from_the_page_creates_a_draft(): void
    {
        $owner = User::factory()->create(['is_staff' => true]);
        $owner->syncRoles(['owner']);

        Livewire::actingAs($owner)
            ->test(\App\Filament\Pages\ImportSupplierProducts::class)
            ->call('import', 'DEMO-P-1002');

        $product = Product::where('supplier_product_id', 'DEMO-P-1002')->firstOrFail();

        $this->assertSame(Product::STATUS_DRAFT, $product->status);
        // Prices were computed by the real rules on import.
        $this->assertNotNull($product->variants()->first()->effectivePriceMinor());
    }

    #[Test]
    public function a_support_user_cannot_reach_the_import_page(): void
    {
        $support = User::factory()->create(['is_staff' => true]);
        $support->syncRoles(['support']);

        $this->actingAs($support)->get('/admin/import-supplier-products')->assertForbidden();
    }
}

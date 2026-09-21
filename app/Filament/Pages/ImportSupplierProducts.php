<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Catalogue\CatalogueImporter;
use App\Domain\Supplier\DTO\ProductSearchQuery;
use App\Domain\Supplier\Exceptions\SupplierException;
use App\Domain\Supplier\Services\SupplierRegistry;
use App\Filament\Resources\ProductResource;
use App\Models\Market;
use App\Models\Product;
use App\Models\Supplier;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * Search the supplier catalogue and import selected products as drafts.
 *
 * The whole supplier catalogue is never bulk imported. An administrator
 * searches, picks specific products, and each one lands as a draft to be
 * merchandised and published deliberately.
 */
class ImportSupplierProducts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Import from supplier';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.import-supplier-products';

    public string $keyword = '';

    public int $page = 1;

    public bool $usStockOnly = true;

    /** @var array<int, array<string, mixed>> */
    public array $results = [];

    public ?string $searchError = null;

    public bool $hasMore = false;

    public bool $hasSearched = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('catalogue.import') ?? false;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    public function search(): void
    {
        $this->page = 1;
        $this->runSearch();
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->runSearch();
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
        $this->runSearch();
    }

    private function runSearch(): void
    {
        $this->searchError = null;
        $this->results = [];
        $this->hasSearched = true;

        $supplier = $this->supplier();

        if ($supplier === null) {
            $this->searchError = 'No supplier integration is configured.';

            return;
        }

        try {
            $result = app(SupplierRegistry::class)->for($supplier)->searchProducts(new ProductSearchQuery(
                keyword: $this->keyword ?: null,
                page: $this->page,
                pageSize: 20,
                warehouseCountries: $this->usStockOnly ? ['US'] : null,
            ));
        } catch (SupplierException $e) {
            // A supplier outage is reported, never papered over with demo data.
            $this->searchError = $e->getMessage();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->searchError = 'The supplier search failed unexpectedly. Check Integrations → Health.';

            return;
        }

        $this->hasMore = $result->hasMore;

        $existing = Product::query()
            ->where('supplier_id', $supplier->id)
            ->pluck('id', 'supplier_product_id');

        $this->results = array_map(fn ($product) => [
            'id' => $product->supplierProductId,
            'name' => $product->name,
            'category' => $product->categoryName,
            'image' => $product->images[0] ?? null,
            'already_imported' => $existing->has($product->supplierProductId),
            'local_id' => $existing->get($product->supplierProductId),
        ], $result->products);
    }

    public function import(string $supplierProductId): void
    {
        abort_unless(self::canAccess(), 403);

        $supplier = $this->supplier();

        if ($supplier === null) {
            return;
        }

        try {
            $product = app(CatalogueImporter::class)->import($supplier, $supplierProductId);
        } catch (Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body($e->getMessage())
                ->send();

            return;
        }

        // Price the imported variants through the real pricing rules so the
        // draft shows a realistic figure rather than nothing.
        $market = Market::default();
        $priced = 0;

        foreach ($product->variants as $variant) {
            if (app(\App\Domain\Pricing\PricingEngine::class)->repriceVariant($variant, $market) !== null) {
                $priced++;
            }
        }

        Notification::make()
            ->success()
            ->title('Imported as a draft')
            ->body(sprintf(
                '%s imported with %d variant(s), %d priced by your rules. It is NOT published — review and publish it when you are ready.',
                $product->name,
                $product->variants()->count(),
                $priced
            ))
            ->actions([
                \Filament\Actions\Action::make('edit')
                    ->label('Open it')
                    ->url(ProductResource::getUrl('edit', ['record' => $product]))
                    ->button(),
            ])
            ->persistent()
            ->send();

        $this->runSearch();
    }

    public function supplier(): ?Supplier
    {
        return Supplier::query()->where('code', 'cjdropshipping')->first();
    }
}

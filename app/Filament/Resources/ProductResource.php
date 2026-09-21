<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Merchandising')
                ->description('Anything written here is yours. A supplier sync will never overwrite a field you have filled in.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(190)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, $state, $set) => $get('slug')
                            ? null
                            : $set('slug', Str::slug((string) $state))),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(190)
                        ->unique(ignoreRecord: true)
                        ->helperText('Used in the product URL. Changing it breaks existing links.'),

                    TextInput::make('subtitle')
                        ->maxLength(190)
                        ->helperText('One short line shown under the name.'),

                    Textarea::make('description')->rows(6)->columnSpanFull(),
                    TextInput::make('brand')->maxLength(120),
                    Textarea::make('suitable_for')->rows(2)
                        ->label('Suitable for')
                        ->helperText('Who this is actually for, e.g. "Medium and large dogs who like to pull".'),
                    Textarea::make('materials')->rows(2),
                    Textarea::make('care_instructions')->rows(2)->label('Care'),
                ])->columns(2),

            Section::make('Publishing')
                ->schema([
                    Select::make('status')
                        ->options([
                            Product::STATUS_DRAFT => 'Draft',
                            Product::STATUS_PUBLISHED => 'Published',
                            Product::STATUS_ARCHIVED => 'Archived',
                        ])
                        ->required()
                        ->default(Product::STATUS_DRAFT)
                        ->helperText('Imported products always start as drafts. Nothing is published automatically.'),

                    DateTimePicker::make('published_at')->seconds(false),
                    Toggle::make('is_featured')->label('Feature on the home page'),
                ])->columns(3),

            Section::make('Taxonomy')
                ->schema([
                    Select::make('categories')->relationship('categories', 'name')->multiple()->preload(),
                    Select::make('petTypes')->relationship('petTypes', 'name')->multiple()->preload()->label('Pet types'),
                    Select::make('collections')->relationship('collections', 'title')->multiple()->preload(),
                ])->columns(3),

            Section::make('Search engine listing')
                ->collapsed()
                ->schema([
                    TextInput::make('seo_title')->maxLength(190),
                    Textarea::make('seo_description')->rows(2)->maxLength(320),
                ]),

            Section::make('Supplier link')
                ->collapsed()
                ->schema([
                    Select::make('supplier_id')->relationship('supplier', 'name')->disabled(),
                    TextInput::make('supplier_product_id')->disabled()
                        ->helperText('The supplier product this was imported from. Read only.'),
                    TextInput::make('supplier_synced_at')->disabled()->label('Last synced'),
                ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()
                    ->description(fn (Product $r) => $r->subtitle),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        Product::STATUS_PUBLISHED => 'success',
                        Product::STATUS_ARCHIVED => 'gray',
                        default => 'warning',
                    })
                    ->sortable(),

                TextColumn::make('variants_count')->counts('variants')->label('Variants'),

                TextColumn::make('price_range')
                    ->label('Price')
                    ->state(function (Product $record): string {
                        $variants = $record->activeVariantsLoaded()
                            ->map(fn ($v) => $v->effectivePriceMinor())
                            ->filter()
                            ->values();

                        if ($variants->isEmpty()) {
                            return '—';
                        }

                        return $variants->min() === $variants->max()
                            ? format_minor($variants->min())
                            : format_minor($variants->min()).' – '.format_minor($variants->max());
                    }),

                TextColumn::make('stock_summary')
                    ->label('Stock')
                    // Unknown is shown as unknown, not folded into zero.
                    ->state(function (Product $record): string {
                        $variants = $record->activeVariantsLoaded();

                        if ($variants->isEmpty()) {
                            return 'No variants';
                        }

                        $known = $variants->filter(fn ($v) => $v->hasKnownStock());

                        if ($known->isEmpty()) {
                            return 'Unknown';
                        }

                        return $known->sum(fn ($v) => $v->availableStock()).' available';
                    })
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        $state === 'Unknown' => 'warning',
                        str_starts_with($state, '0 ') => 'danger',
                        default => 'success',
                    }),

                IconColumn::make('is_featured')->boolean()->label('Featured')->toggleable(),

                TextColumn::make('supplier.name')->label('Supplier')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime('j M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Product::STATUS_DRAFT => 'Draft',
                    Product::STATUS_PUBLISHED => 'Published',
                    Product::STATUS_ARCHIVED => 'Archived',
                ]),
                SelectFilter::make('categories')->relationship('categories', 'name')->multiple()->preload(),
                SelectFilter::make('petTypes')->relationship('petTypes', 'name')->multiple()->preload()->label('Pet type'),
                TernaryFilter::make('is_featured')->label('Featured'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // The list computes prices and stock per row, so load them once.
        return parent::getEloquentQuery()->with(['variants.warehouseStocks', 'supplier']);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VariantsRelationManager::class,
            RelationManagers\MediaRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('catalogue.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('catalogue.manage') ?? false;
    }
}

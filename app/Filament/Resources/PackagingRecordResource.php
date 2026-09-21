<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PackagingRecordResource\Pages;
use App\Models\PackagingRecord;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class PackagingRecordResource extends Resource
{
    protected static ?string $model = PackagingRecord::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static string|\UnitEnum|null $navigationGroup = 'Fulfilment';

    protected static ?string $navigationLabel = 'Packaging';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('explainer')
                ->label('')
                ->content(new HtmlString(
                    '<div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">'
                    .'<p class="font-semibold">Uploading a design does not make packaging available.</p>'
                    .'<p>Branded packaging is a box placed around the product, keeping the manufacturer\'s own labels intact. '
                    .'It becomes usable only once the supplier has approved it, produced stock, and that stock is confirmed '
                    .'at the fulfilling warehouse. Until the state below reads <strong>Available</strong> with a quantity, '
                    .'orders ship in standard packaging and no branded-box promise is shown to customers.</p></div>'
                ))
                ->columnSpanFull(),

            Section::make('Packaging')->schema([
                TextInput::make('name')->required()->maxLength(120),

                Select::make('type')->options([
                    PackagingRecord::TYPE_STANDARD => 'Standard supplier packaging',
                    PackagingRecord::TYPE_BRANDED_BOX => 'Branded box around the product',
                ])->required()->default(PackagingRecord::TYPE_STANDARD)->live(),

                Select::make('state')->options([
                    PackagingRecord::STATE_PLANNED => 'Planned — design stage only',
                    PackagingRecord::STATE_AWAITING_APPROVAL => 'Awaiting supplier approval',
                    PackagingRecord::STATE_APPROVED => 'Approved, not yet produced',
                    PackagingRecord::STATE_STOCKING => 'Being produced or shipped to the warehouse',
                    PackagingRecord::STATE_AVAILABLE => 'Available at the warehouse',
                    PackagingRecord::STATE_UNAVAILABLE => 'Unavailable',
                ])->required()->default(PackagingRecord::STATE_PLANNED),

                Select::make('warehouse_id')->relationship('warehouse', 'name')->label('Warehouse')->searchable()->preload(),

                TextInput::make('available_quantity')->numeric()->minValue(0)
                    ->helperText('Units confirmed at the warehouse. Leave empty if unknown.'),

                TextInput::make('unit_cost_minor')->label('Unit cost')->numeric()->prefix('$')->step(0.01)
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2, '.', ''))
                    ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round((float) $state * 100)),

                TextInput::make('added_weight_grams')->numeric()->suffix('g')
                    ->helperText('Included in shipping estimates when known.'),
            ])->columns(3),

            Section::make('Supplier API support')
                ->description('Leave both off unless the supplier has confirmed the API can actually do this for your account.')
                ->schema([
                    Toggle::make('api_selectable')->label('API can select this packaging on an order'),
                    Toggle::make('api_reportable')->label('API reports packaging stock'),
                    Textarea::make('manual_process_notes')->rows(3)->columnSpanFull()
                        ->label('Manual process')
                        ->helperText('If the API cannot do this, write down the manual arrangement with your supplier agent here.'),
                ])->columns(2),

            Section::make('Design and compatibility')->schema([
                FileUpload::make('design_asset_path')
                    ->label('Design artwork')
                    ->disk('public')->directory('packaging')->visibility('public')
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'application/pdf'])
                    ->maxSize(10240),

                Select::make('variants')->relationship('variants', 'sku')->multiple()->preload()->searchable()
                    ->label('Compatible variants')
                    ->helperText('Only these products may ship in this packaging.'),

                TextInput::make('supplier_packaging_id')->label('Supplier packaging ID')
                    ->helperText('Only if your supplier has given you one.'),

                Textarea::make('readiness_notes')->rows(2)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),

                TextColumn::make('type')->badge()
                    ->formatStateUsing(fn (string $state) => $s === PackagingRecord::TYPE_BRANDED_BOX ? 'Branded box' : 'Standard'),

                TextColumn::make('state')->badge()
                    ->color(fn (string $state) => match ($state) {
                        PackagingRecord::STATE_AVAILABLE => 'success',
                        PackagingRecord::STATE_UNAVAILABLE => 'danger',
                        PackagingRecord::STATE_STOCKING, PackagingRecord::STATE_APPROVED => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (PackagingRecord $r) => $r->stateLabel()),

                TextColumn::make('usable')
                    ->label('Usable now?')
                    ->state(fn (PackagingRecord $r) => $r->blockingReason() ?? 'Yes')
                    ->color(fn (PackagingRecord $r) => $r->blockingReason() === null ? 'success' : 'warning')
                    ->wrap(),

                TextColumn::make('warehouse.code')->label('Warehouse')->placeholder('Any'),
                TextColumn::make('available_quantity')->label('Qty')->placeholder('Unknown'),
                IconColumn::make('api_selectable')->boolean()->label('API select'),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackagingRecords::route('/'),
            'create' => Pages\CreatePackagingRecord::route('/create'),
            'edit' => Pages\EditPackagingRecord::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('packaging.manage') ?? false;
    }
}

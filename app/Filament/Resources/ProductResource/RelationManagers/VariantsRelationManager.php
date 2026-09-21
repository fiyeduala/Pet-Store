<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Domain\Pricing\PricingEngine;
use App\Models\Market;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants, pricing and stock';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identity')->schema([
                TextInput::make('sku')->required()->unique(ignoreRecord: true)->maxLength(64),
                TextInput::make('option_summary')->label('Option')->maxLength(120)
                    ->helperText('What the customer picks, e.g. "Medium / Sage".'),
                Toggle::make('is_active')->default(true),
            ])->columns(3),

            Section::make('Price')
                ->description('A manual price wins over the pricing rules until you clear it.')
                ->schema([
                    TextInput::make('supplier_cost_minor')
                        ->label('Supplier cost')
                        ->disabled()
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2))
                        ->helperText('Reported by the supplier. Read only.'),

                    TextInput::make('computed_price_minor')
                        ->label('Rule price')
                        ->disabled()
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2)),

                    TextInput::make('manual_price_minor')
                        ->label('Manual price override')
                        ->numeric()
                        ->prefix('$')
                        ->step(0.01)
                        ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2, '.', ''))
                        ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round((float) $state * 100))
                        ->helperText('Leave empty to let the pricing rules decide.'),

                    TextInput::make('compare_at_price_minor')
                        ->label('Compare-at price')
                        ->numeric()->prefix('$')->step(0.01)
                        ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2, '.', ''))
                        ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round((float) $state * 100)),
                ])->columns(2),

            Section::make('Physical')->collapsed()->schema([
                TextInput::make('weight_grams')->numeric()->suffix('g'),
                TextInput::make('length_mm')->numeric()->suffix('mm'),
                TextInput::make('width_mm')->numeric()->suffix('mm'),
                TextInput::make('height_mm')->numeric()->suffix('mm'),
            ])->columns(4),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sku')
            ->columns([
                TextColumn::make('sku')->searchable(),
                TextColumn::make('option_summary')->label('Option'),

                TextColumn::make('supplier_cost_minor')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state, ProductVariant $r) => format_minor(
                        $state === null ? null : (int) $r->getRawOriginal('supplier_cost_minor'),
                        $r->supplier_cost_currency ?? 'USD'
                    )),

                TextColumn::make('price')
                    ->label('Retail')
                    ->state(fn (ProductVariant $r) => format_minor($r->effectivePriceMinor(), $r->currency))
                    ->description(fn (ProductVariant $r) => $r->hasManualPriceOverride() ? 'manual override' : $r->applied_pricing_rule),

                TextColumn::make('contribution')
                    ->label('Contribution')
                    ->state(function (ProductVariant $r): string {
                        $breakdown = app(PricingEngine::class)->breakdown($r, Market::default());

                        return $breakdown->contribution->format()
                            .' ('.($breakdown->marginPercent() ?? 0).'% margin)';
                    })
                    ->color(fn (ProductVariant $r) => app(PricingEngine::class)
                        ->breakdown($r, Market::default())->breachesFloor ? 'danger' : null)
                    ->tooltip('Retail minus cost, estimated shipping, packaging and gateway fees. Excludes advertising and overhead.'),

                TextColumn::make('stock')
                    ->label('Stock by warehouse')
                    // Each warehouse is listed separately; totals across
                    // countries would be misleading for a US order.
                    ->state(fn (ProductVariant $r) => $r->warehouseStocks
                        ->map(fn ($state) => $s->warehouse->code.': '.($s->quantity_known ? $s->quantity : 'unknown'))
                        ->implode(' · ') ?: 'None recorded')
                    ->wrap(),

                IconColumn::make('is_active')->boolean(),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),

                Action::make('reprice')
                    ->label('Reprice')
                    ->icon('heroicon-o-calculator')
                    ->requiresConfirmation()
                    ->modalDescription('Recalculates the rule price. Your manual override, if set, still wins.')
                    ->action(function (ProductVariant $record): void {
                        $price = app(PricingEngine::class)->repriceVariant($record, Market::default());

                        Notification::make()
                            ->title($price === null ? 'No rule matched, or no cost recorded' : 'Repriced to '.$price->format())
                            ->color($price === null ? 'warning' : 'success')
                            ->send();
                    }),

                Action::make('clear_override')
                    ->label('Clear manual price')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (ProductVariant $record) => $record->hasManualPriceOverride())
                    ->action(function (ProductVariant $record): void {
                        $record->forceFill(['manual_price_minor' => null])->save();
                        app(PricingEngine::class)->repriceVariant($record, Market::default());

                        Notification::make()->title('Manual price cleared; rule price now applies')->success()->send();
                    }),
            ]);
    }
}

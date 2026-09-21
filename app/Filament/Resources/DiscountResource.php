<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DiscountResource\Pages;
use App\Models\Discount;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class DiscountResource extends Resource
{
    protected static ?string $model = Discount::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Money';

    protected static ?string $navigationLabel = 'Discounts';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(40)
                ->helperText('What the customer types at checkout. Case is ignored.'),
            TextInput::make('name')->required()->maxLength(120),
            Select::make('type')->required()->live()->options([
                'percentage' => 'Percentage off the subtotal',
                'fixed' => 'Fixed amount off',
                'free_shipping' => 'Free shipping',
            ]),
            TextInput::make('percentage')->numeric()->suffix('%')->minValue(0)->maxValue(100)
                ->visible(fn ($get) => $get('type') === 'percentage')->required(fn ($get) => $get('type') === 'percentage'),
            TextInput::make('amount_minor')->label('Amount off')->numeric()->prefix('$')->step(0.01)
                ->visible(fn ($get) => $get('type') === 'fixed')
                ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2, '.', ''))
                ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round((float) $state * 100)),
            TextInput::make('min_subtotal_minor')->label('Minimum subtotal')->numeric()->prefix('$')->step(0.01)
                ->formatStateUsing(fn ($state) => $state === null ? null : number_format((int) $state / 100, 2, '.', ''))
                ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round((float) $state * 100)),
            TextInput::make('usage_limit')->numeric()->label('Total uses allowed')->helperText('Leave empty for unlimited.'),
            TextInput::make('per_customer_limit')->numeric()->label('Uses per customer'),
            DateTimePicker::make('starts_at')->seconds(false),
            DateTimePicker::make('ends_at')->seconds(false),
            Toggle::make('is_active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('code')->searchable()->copyable(),
            TextColumn::make('name')->searchable(),
            TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),
            TextColumn::make('value')->label('Value')->state(fn ($record) => match ($record->type) {
                'percentage' => rtrim(rtrim((string) $record->percentage, '0'), '.').'%',
                'fixed' => format_minor((int) $record->getRawOriginal('amount_minor'), $record->currency),
                default => 'Free shipping',
            }),
            TextColumn::make('used_count')->label('Used')
                ->formatStateUsing(fn ($state, $record) => $record->usage_limit ? "{$state} / {$record->usage_limit}" : (string) $state),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('ends_at')->dateTime('j M Y')->placeholder('No end date'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiscounts::route('/'),
            'create' => Pages\CreateDiscount::route('/create'),
            'edit' => Pages\EditDiscount::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('discounts.manage') ?? false;
    }
}

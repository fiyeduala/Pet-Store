<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer accounts.
 *
 * Read-only by design: there is no reason for staff to edit a customer's
 * details from here, and every avoidable write is an avoidable mistake.
 * Guest purchasers do not appear — they have no account; find their orders
 * through Orders instead.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        // Staff accounts are managed separately and are not customers.
        return parent::getEloquentQuery()
            ->where('is_staff', false)
            ->withCount('orders')
            ->withSum(['orders as lifetime_minor' => fn (Builder $q) => $q->where('is_demo', false)], 'total_minor');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable(),

                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->state(fn (User $record) => $record->email_verified_at !== null)
                    ->tooltip('Past guest orders are only linked to an account once the address is verified.'),

                TextColumn::make('orders_count')->label('Orders')->sortable(),

                TextColumn::make('lifetime_minor')
                    ->label('Lifetime value')
                    ->state(fn (User $record) => format_minor((int) ($record->lifetime_minor ?? 0), 'USD'))
                    ->tooltip('Gross order value, excluding demo orders. Not net of refunds.')
                    ->sortable(),

                TextColumn::make('last_login_at')->label('Last seen')->dateTime('j M Y')->placeholder('Never')->sortable(),
                TextColumn::make('created_at')->label('Joined')->dateTime('j M Y')->sortable()->toggleable(),

                IconColumn::make('accepts_marketing')->boolean()->label('Marketing')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('email_verified_at')->label('Email verified')->nullable(),
                TernaryFilter::make('accepts_marketing')->label('Accepts marketing'),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('customers.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('customers.manage') ?? false;
    }
}

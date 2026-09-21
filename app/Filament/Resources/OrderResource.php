<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationBadge(): ?string
    {
        $count = Order::awaitingApproval()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('medium'),

                TextColumn::make('placed_at')->label('Placed')->dateTime('j M H:i')->sortable(),

                TextColumn::make('email')->searchable()->toggleable()
                    ->description(fn (Order $r) => $r->is_guest ? 'Guest' : 'Account'),

                TextColumn::make('total_minor')
                    ->label('Total')
                    ->state(fn (Order $r) => format_minor((int) $r->getRawOriginal('total_minor'), $r->currency))
                    ->sortable(),

                // The five machines are shown separately, never merged,
                // so "paid" can never be read as "shipped".
                TextColumn::make('payment_state')->label('Payment')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Order::PAYMENT_PAID => 'success',
                        Order::PAYMENT_FAILED, Order::PAYMENT_CANCELLED => 'danger',
                        Order::PAYMENT_REFUNDED, Order::PAYMENT_PARTIALLY_REFUNDED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),

                TextColumn::make('approval_state')->label('Approval')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Order::APPROVAL_APPROVED => 'success',
                        Order::APPROVAL_REJECTED => 'danger',
                        Order::APPROVAL_ON_HOLD => 'danger',
                        Order::APPROVAL_AWAITING => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),

                TextColumn::make('supplier_order_state')->label('Supplier order')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Order::SUPPLIER_CONFIRMED => 'success',
                        Order::SUPPLIER_REJECTED, Order::SUPPLIER_CANCELLED => 'danger',
                        Order::SUPPLIER_RECONCILING => 'danger',
                        Order::SUPPLIER_NOT_SUBMITTED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),

                TextColumn::make('supplier_payment_state')->label('Supplier paid')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Order::SUPPLIER_PAY_PAID => 'success',
                        Order::SUPPLIER_PAY_FAILED, Order::SUPPLIER_PAY_INSUFFICIENT => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state))
                    ->toggleable(),

                TextColumn::make('shipment_state')->label('Shipment')->badge()
                    ->color(fn (string $state) => match ($state) {
                        Order::SHIPMENT_DELIVERED => 'success',
                        Order::SHIPMENT_RETURNED => 'danger',
                        Order::SHIPMENT_NONE => 'gray',
                        default => 'info',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),

                IconColumn::make('is_demo')
                    ->label('Demo')
                    ->boolean()
                    ->trueIcon('heroicon-o-beaker')
                    ->falseIcon('heroicon-o-banknotes')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(fn (Order $r) => $r->is_demo ? $r->demo_reason : 'Real money'),
            ])
            ->filters([
                SelectFilter::make('approval_state')->label('Approval')->options([
                    Order::APPROVAL_AWAITING => 'Awaiting approval',
                    Order::APPROVAL_APPROVED => 'Approved',
                    Order::APPROVAL_ON_HOLD => 'On hold',
                    Order::APPROVAL_REJECTED => 'Rejected',
                ]),
                SelectFilter::make('payment_state')->label('Payment')->options([
                    Order::PAYMENT_PENDING => 'Pending',
                    Order::PAYMENT_PAID => 'Paid',
                    Order::PAYMENT_FAILED => 'Failed',
                    Order::PAYMENT_REFUNDED => 'Refunded',
                    Order::PAYMENT_PARTIALLY_REFUNDED => 'Partially refunded',
                ]),
                SelectFilter::make('supplier_order_state')->label('Supplier order')->options([
                    Order::SUPPLIER_NOT_SUBMITTED => 'Not submitted',
                    Order::SUPPLIER_SUBMITTED => 'Submitted',
                    Order::SUPPLIER_CONFIRMED => 'Confirmed',
                    Order::SUPPLIER_RECONCILING => 'Needs reconciliation',
                    Order::SUPPLIER_REJECTED => 'Rejected',
                ]),
                TernaryFilter::make('is_demo')
                    ->label('Demo orders')
                    ->placeholder('All orders')
                    ->trueLabel('Demo only')
                    ->falseLabel('Real money only'),
                Filter::make('needs_attention')
                    ->label('Needs attention')
                    ->query(fn (Builder $q) => $q->where(fn (Builder $w) => $w
                        ->where('approval_state', Order::APPROVAL_ON_HOLD)
                        ->orWhere('supplier_order_state', Order::SUPPLIER_RECONCILING)
                        ->orWhere('supplier_payment_state', Order::SUPPLIER_PAY_INSUFFICIENT)
                        ->orWhereHas('exceptions', fn (Builder $e) => $e->open()))),
            ])
            ->recordActions([ViewAction::make()])
            ->defaultSort('id', 'desc')
            ->poll('60s');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['market']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('orders.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

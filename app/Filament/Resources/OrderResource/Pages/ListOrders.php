<?php

declare(strict_types=1);

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        return [
            'attention' => Tab::make('Needs attention')
                ->badge(fn () => Order::query()
                    ->where(fn (Builder $w) => $w
                        ->where('approval_state', Order::APPROVAL_ON_HOLD)
                        ->orWhere('supplier_order_state', Order::SUPPLIER_RECONCILING)
                        ->orWhere('supplier_payment_state', Order::SUPPLIER_PAY_INSUFFICIENT))
                    ->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('approval_state', Order::APPROVAL_ON_HOLD)
                    ->orWhere('supplier_order_state', Order::SUPPLIER_RECONCILING)
                    ->orWhere('supplier_payment_state', Order::SUPPLIER_PAY_INSUFFICIENT))),

            'awaiting_approval' => Tab::make('Awaiting approval')
                ->badge(fn () => Order::awaitingApproval()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $q) => $q->awaitingApproval()),

            'to_fulfil' => Tab::make('Approved, not submitted')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('approval_state', Order::APPROVAL_APPROVED)
                    ->where('supplier_order_state', Order::SUPPLIER_NOT_SUBMITTED)),

            'in_transit' => Tab::make('In transit')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->whereIn('shipment_state', [Order::SHIPMENT_SHIPPED, Order::SHIPMENT_PARTIAL])),

            'all' => Tab::make('All orders'),

            // Demo orders are kept out of the default views so they cannot
            // be mistaken for real trade.
            'demo' => Tab::make('Demo')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('is_demo', true)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Order::awaitingApproval()->exists() ? 'awaiting_approval' : 'all';
    }

    protected function getTableQuery(): ?Builder
    {
        // Real-money orders by default; the Demo tab opts in explicitly.
        return parent::getTableQuery()?->when(
            $this->activeTab !== 'demo',
            fn (Builder $q) => $q->where('is_demo', false)
        );
    }
}

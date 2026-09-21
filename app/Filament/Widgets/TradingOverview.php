<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Support\Money\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Trading summary.
 *
 * Deliberate choices here:
 *  - Demo orders are excluded entirely.
 *  - Revenue is net of tax, discounts and refunds.
 *  - The margin figure is called CONTRIBUTION, never profit, because
 *    advertising and overhead are not known to the application.
 *  - Where cost data is incomplete, the widget says so instead of
 *    quietly reporting a flattering number.
 */
class TradingOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Last 30 days';

    protected function getStats(): array
    {
        $since = now()->subDays(30);

        $orders = Order::query()
            ->realMoney()
            ->where('payment_state', '!=', Order::PAYMENT_PENDING)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->get();

        $currency = $orders->first()->currency ?? 'USD';

        $gross = (int) $orders->sum(fn (Order $o) => (int) $o->getRawOriginal('total_minor'));
        $tax = (int) $orders->sum(fn (Order $o) => (int) $o->getRawOriginal('tax_minor'));
        $refunded = (int) $orders->sum(fn (Order $o) => (int) $o->getRawOriginal('refunded_minor'));
        $netRevenue = $gross - $tax - $refunded;

        $withCosts = $orders->filter(fn (Order $o) => $o->contributionMinor() !== null);
        $contribution = (int) $withCosts->sum(fn (Order $o) => $o->contributionMinor());
        $incomplete = $orders->count() - $withCosts->count();

        $awaiting = Order::awaitingApproval()->realMoney()->count();

        return [
            Stat::make('Orders paid', (string) $orders->count())
                ->description('Excludes demo orders')
                ->color('primary'),

            Stat::make('Net revenue', Money::ofMinor($netRevenue, $currency)->format())
                ->description('Excluding tax, discounts and refunds')
                ->color('success'),

            Stat::make('Contribution', $withCosts->isEmpty()
                ? 'Not calculable'
                : Money::ofMinor($contribution, $currency)->format())
                ->description($incomplete > 0
                    ? "Before advertising and overhead. {$incomplete} order(s) have incomplete cost data and are excluded."
                    : 'Before advertising and overhead')
                ->color($incomplete > 0 ? 'warning' : 'success'),

            Stat::make('Awaiting approval', (string) $awaiting)
                ->description($awaiting > 0 ? 'Paid orders waiting for you' : 'Nothing waiting')
                ->color($awaiting > 0 ? 'warning' : 'gray'),
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }
}

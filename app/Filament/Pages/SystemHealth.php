<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Heartbeat;
use App\Models\OperationalException;
use App\Models\PaymentGateway;
use App\Models\Supplier;
use App\Models\SupplierSyncLog;
use App\Models\WarehouseStock;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * One screen answering "is this shop actually working right now?".
 *
 * Deliberately shows data freshness and missing configuration rather than
 * a reassuring green tick.
 */
class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Health';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.system-health';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('integrations.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return [
            'heartbeats' => Heartbeat::orderBy('key')->get(),
            'suppliers' => Supplier::withCount('syncLogs')->get(),
            'gateways' => PaymentGateway::orderBy('position')->get(),
            'recentSyncs' => SupplierSyncLog::with('supplier')->latest('id')->limit(10)->get(),
            'openExceptions' => OperationalException::open()->count(),
            'criticalExceptions' => OperationalException::open()->where('severity', 'critical')->count(),
            'failedJobs' => DB::table('failed_jobs')->count(),
            'pendingJobs' => DB::table('jobs')->count(),
            'stockFreshness' => $this->stockFreshness(),
            'blockers' => $this->launchBlockers(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stockFreshness(): array
    {
        $total = WarehouseStock::count();

        if ($total === 0) {
            return ['total' => 0, 'known' => 0, 'stale' => 0, 'unknown' => 0, 'last_sync' => null];
        }

        $known = WarehouseStock::where('quantity_known', true)->count();
        $stale = WarehouseStock::where('quantity_known', true)
            ->whereNotNull('stale_after')->where('stale_after', '<', now())->count();

        return [
            'total' => $total,
            'known' => $known,
            'stale' => $stale,
            'unknown' => $total - $known,
            'last_sync' => WarehouseStock::max('synced_at'),
        ];
    }

    /**
     * Things that must be true before real money can be taken.
     *
     * @return array<int, array{label: string, ok: bool, detail: string}>
     */
    private function launchBlockers(): array
    {
        $liveGateway = PaymentGateway::query()
            ->where('is_enabled', true)
            ->where('mode', PaymentGateway::MODE_LIVE)
            ->where('is_verified', true)
            ->first();

        $supplier = Supplier::where('code', 'cjdropshipping')->first();
        $market = \App\Models\Market::where('code', 'US')->first();

        return [
            [
                'label' => 'A verified live payment method',
                'ok' => $liveGateway !== null,
                'detail' => $liveGateway !== null
                    ? $liveGateway->name.' is live and recorded as verified.'
                    : 'No payment method is both live and verified, so no real payment can be taken. Checkout will fail clearly rather than pretending.',
            ],
            [
                'label' => 'Supplier integration in live mode',
                'ok' => $supplier?->mode === Supplier::MODE_LIVE,
                'detail' => $supplier?->mode === Supplier::MODE_LIVE
                    ? 'Live. Orders will be placed with the real supplier.'
                    : 'In '.($supplier?->mode ?? 'unknown').' mode. No real supplier orders will be placed.',
            ],
            [
                'label' => 'Supplier credentials verified',
                'ok' => $supplier?->connection_state === 'ok',
                'detail' => $supplier?->connection_state === 'ok'
                    ? 'Last authentication succeeded '.($supplier->last_authenticated_at?->diffForHumans() ?? '').'.'
                    : 'Run "Test connection" on the Integrations page against the real account.',
            ],
            [
                'label' => 'Tax configured or deliberately disabled',
                'ok' => $market !== null && ($market->tax_mode !== 'manual' || $market->taxRules()->where('is_active', true)->exists()),
                'detail' => $market?->tax_mode === 'disabled'
                    ? 'Tax is off for the US market. Confirm with an accountant that no tax is due before selling.'
                    : 'Manual tax mode with '.($market?->taxRules()->where('is_active', true)->count() ?? 0).' active rule(s).',
            ],
            [
                'label' => 'Policy pages reviewed',
                'ok' => ! \App\Models\ContentPage::where('requires_owner_review', true)->exists(),
                'detail' => \App\Models\ContentPage::where('requires_owner_review', true)->count()
                    .' page(s) are still marked as draft and awaiting your review.',
            ],
            [
                'label' => 'Scheduler running',
                'ok' => Heartbeat::where('key', 'scheduler')->first()?->isHealthy() ?? false,
                'detail' => Heartbeat::where('key', 'scheduler')->first()?->statusLabel()
                    ?? 'The scheduler has never reported in. Add the cron entry from the deployment guide.',
            ],
            [
                'label' => 'Queue worker running',
                'ok' => Heartbeat::where('key', 'queue_worker')->first()?->isHealthy() ?? false,
                'detail' => Heartbeat::where('key', 'queue_worker')->first()?->statusLabel()
                    ?? 'No queue worker has reported in. Background syncs and emails will not run.',
            ],
        ];
    }
}

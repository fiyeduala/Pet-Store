<?php

declare(strict_types=1);

use App\Jobs\RefreshTracking;
use App\Jobs\SyncSupplierStock;
use App\Models\Heartbeat;
use App\Models\Supplier;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
| Everything here is queued rather than run inline, so a slow supplier
| cannot stall the scheduler. On cPanel the queue is drained by a bounded
| worker started from cron; see docs/deployment-cpanel.md.
|
| withoutOverlapping is essential: two concurrent syncs would both hit the
| supplier's 1 request/second limit and fight each other.
*/

Schedule::command('petstore:heartbeat scheduler')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::call(function (): void {
    foreach (Supplier::where('is_enabled', true)->get() as $supplier) {
        SyncSupplierStock::dispatch($supplier->id);
    }
})->hourly()->name('queue-stock-sync')->withoutOverlapping();

Schedule::job(new RefreshTracking)
    ->everyThirtyMinutes()
    ->name('refresh-tracking')
    ->withoutOverlapping();

Schedule::command('petstore:drain-outbox')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=336')->daily();
Schedule::call(fn () => \App\Models\Cart::where('last_activity_at', '<', now()->subDays(30))->delete())
    ->daily()
    ->name('prune-carts');
Schedule::call(fn () => \App\Models\ShippingQuote::where('expires_at', '<', now()->subDays(2))->delete())
    ->daily()
    ->name('prune-quotes');

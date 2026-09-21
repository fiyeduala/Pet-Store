<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * A bounded queue worker, for hosts with no persistent process manager.
 *
 * cPanel usually cannot run a supervised daemon, so cron calls this every
 * few minutes. It processes whatever is waiting, stops well inside the
 * host's process limits, and holds a lock so overlapping cron runs do not
 * stack workers on top of each other.
 *
 * On a VPS, run `queue:work` under Supervisor or systemd instead and leave
 * this out of cron entirely.
 */
class RunQueueWorker extends Command
{
    protected $signature = 'petstore:work
                            {--max-seconds=55 : Stop after this long}
                            {--max-jobs=50 : Stop after this many jobs}';

    protected $description = 'Process queued jobs for a bounded time, safe to call from cron.';

    public function handle(): int
    {
        $lock = Cache::lock('petstore:queue-worker', (int) $this->option('max-seconds') + 30);

        if (! $lock->get()) {
            $this->line('Another worker is already running; exiting.');

            return self::SUCCESS;
        }

        try {
            Heartbeat::beat('queue_worker', 'ok', 'Worker started.', ['pid' => getmypid()]);

            Artisan::call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => (int) $this->option('max-seconds'),
                '--max-jobs' => (int) $this->option('max-jobs'),
                '--tries' => 3,
                '--backoff' => 30,
                // Bounded so one wedged job cannot consume the whole window.
                '--timeout' => 60,
                '--no-interaction' => true,
            ], $this->output);

            Heartbeat::beat('queue_worker', 'ok', 'Worker finished cleanly.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}

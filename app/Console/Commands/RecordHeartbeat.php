<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Heartbeat;
use Illuminate\Console\Command;

/**
 * Records that a named background process ran.
 *
 * Admin reads these to tell the difference between "nothing has happened"
 * and "nothing is running", which look identical otherwise.
 */
class RecordHeartbeat extends Command
{
    protected $signature = 'petstore:heartbeat {key} {--interval=5 : Expected minutes between runs} {--message=}';

    protected $description = 'Record a liveness heartbeat for a background process.';

    public function handle(): int
    {
        $heartbeat = Heartbeat::beat(
            $this->argument('key'),
            'ok',
            $this->option('message') ?: 'Ran at '.now()->toDateTimeString(),
        );

        $heartbeat->forceFill(['expected_interval_minutes' => (int) $this->option('interval')])->save();

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends the side effects recorded by the durable outbox.
 *
 * A message is written in the same transaction as the state change that
 * requires it, so a crash between committing and sending cannot lose it.
 */
class DrainOutbox extends Command
{
    protected $signature = 'petstore:drain-outbox {--limit=50}';

    protected $description = 'Process pending outbox messages.';

    public function handle(): int
    {
        $processed = 0;
        $failed = 0;

        OutboxMessage::pending()
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (OutboxMessage $message) use (&$processed, &$failed): void {
                try {
                    DB::transaction(function () use ($message): void {
                        // Claim the row first so a second worker cannot take it.
                        $claimed = OutboxMessage::whereKey($message->id)
                            ->whereNull('processed_at')
                            ->lockForUpdate()
                            ->first();

                        if ($claimed === null) {
                            return;
                        }

                        $this->dispatchMessage($claimed);

                        $claimed->forceFill(['processed_at' => now(), 'last_error' => null])->save();
                    });

                    $processed++;
                } catch (Throwable $e) {
                    $failed++;

                    $message->forceFill([
                        'attempts' => $message->attempts + 1,
                        'last_error' => $e->getMessage(),
                        // Exponential back-off, capped so it always retries eventually.
                        'available_at' => now()->addMinutes(min(60, 2 ** min(6, $message->attempts))),
                    ])->save();
                }
            });

        Heartbeat::beat('outbox', $failed > 0 ? 'degraded' : 'ok', "{$processed} sent, {$failed} failed.");

        $this->info("Outbox: {$processed} sent, {$failed} failed.");

        return self::SUCCESS;
    }

    private function dispatchMessage(OutboxMessage $message): void
    {
        $type = $message->type;
        $payload = $message->payload;

        match ($type) {
            'notification' => $this->sendNotification($payload),
            default => throw new \RuntimeException("No handler is registered for outbox type [{$type}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendNotification(array $payload): void
    {
        $class = $payload['notification'] ?? null;

        if (! is_string($class) || ! class_exists($class)) {
            throw new \RuntimeException('Outbox notification class is missing or unknown.');
        }

        $notifiable = \Illuminate\Support\Facades\Notification::route('mail', $payload['email']);
        $arguments = [];

        foreach ($payload['arguments'] ?? [] as $argument) {
            $arguments[] = is_array($argument) && isset($argument['model'], $argument['id'])
                ? $argument['model']::findOrFail($argument['id'])
                : $argument;
        }

        $notifiable->notify(new $class(...$arguments));
    }
}

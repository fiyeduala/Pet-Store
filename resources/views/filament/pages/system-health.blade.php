<x-filament-panels::page>

    <x-filament::section heading="Before you take real money"
                         description="Each of these must be true. Nothing here is assumed in your favour.">
        <ul class="space-y-2">
            @foreach ($blockers as $blocker)
                <li class="flex items-start gap-3 rounded-lg border p-3 text-sm
                    {{ $blocker['ok']
                        ? 'border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950'
                        : 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950' }}">
                    <span class="mt-0.5 shrink-0">{{ $blocker['ok'] ? '✓' : '!' }}</span>
                    <div>
                        <p class="font-medium">{{ $blocker['label'] }}</p>
                        <p class="text-gray-600 dark:text-gray-400">{{ $blocker['detail'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <div class="grid gap-6 lg:grid-cols-2">

        <x-filament::section heading="Background workers">
            @if ($heartbeats->isEmpty())
                <p class="text-sm text-gray-500">
                    Nothing has reported in yet. Until the scheduler and a queue worker are running,
                    stock syncs, tracking updates and emails will not happen.
                </p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($heartbeats as $heartbeat)
                        <li class="flex items-start justify-between gap-3 border-b border-gray-100 pb-2 last:border-0 dark:border-gray-800">
                            <div>
                                <p class="font-medium">{{ ucfirst(str_replace('_', ' ', $heartbeat->key)) }}</p>
                                <p class="text-xs text-gray-500">{{ $heartbeat->last_message }}</p>
                            </div>
                            <span class="shrink-0 text-xs {{ $heartbeat->isHealthy() ? 'text-green-600' : 'text-red-600' }}">
                                {{ $heartbeat->statusLabel() }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                <div><p class="text-xs text-gray-500">Queued jobs</p><p class="font-medium">{{ $pendingJobs }}</p></div>
                <div>
                    <p class="text-xs text-gray-500">Failed jobs</p>
                    <p class="font-medium {{ $failedJobs > 0 ? 'text-red-600' : '' }}">{{ $failedJobs }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Stock data freshness">
            @if ($stockFreshness['total'] === 0)
                <p class="text-sm text-gray-500">No stock has been synced yet.</p>
            @else
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Warehouse readings</dt><dd>{{ $stockFreshness['total'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Supplier reported a number</dt><dd>{{ $stockFreshness['known'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Not reported (treated as unavailable)</dt>
                        <dd class="{{ $stockFreshness['unknown'] > 0 ? 'text-amber-600' : '' }}">{{ $stockFreshness['unknown'] }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Past their freshness window</dt>
                        <dd class="{{ $stockFreshness['stale'] > 0 ? 'text-amber-600' : '' }}">{{ $stockFreshness['stale'] }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                        <dt class="text-gray-500">Last sync</dt>
                        <dd>{{ $stockFreshness['last_sync'] ? \Illuminate\Support\Carbon::parse($stockFreshness['last_sync'])->diffForHumans() : 'Never' }}</dd>
                    </div>
                </dl>
            @endif
        </x-filament::section>

        <x-filament::section heading="Integration modes">
            <ul class="space-y-2 text-sm">
                @foreach ($suppliers as $supplier)
                    <li class="flex items-center justify-between">
                        <span>{{ $supplier->name }}</span>
                        <span class="rounded px-2 py-0.5 text-xs
                            {{ $supplier->mode === 'live' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                            {{ $supplier->mode }}
                        </span>
                    </li>
                @endforeach
                @foreach ($gateways as $gateway)
                    <li class="flex items-center justify-between">
                        <span>{{ $gateway->name }} {{ $gateway->is_enabled ? '' : '(off)' }}</span>
                        <span class="rounded px-2 py-0.5 text-xs
                            {{ $gateway->isLiveReady() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">
                            {{ $gateway->mode }}{{ $gateway->mode === 'live' && ! $gateway->is_verified ? ' (unverified)' : '' }}
                        </span>
                    </li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-500">
                A demo or sandbox payment can never be fulfilled through a live supplier, and vice versa.
                Mixed-mode attempts are refused and logged in the exception queue.
            </p>
        </x-filament::section>

        <x-filament::section heading="Recent supplier syncs">
            @if ($recentSyncs->isEmpty())
                <p class="text-sm text-gray-500">No syncs have run yet.</p>
            @else
                <ul class="space-y-1.5 text-sm">
                    @foreach ($recentSyncs as $sync)
                        <li class="flex items-center justify-between gap-2 border-b border-gray-100 pb-1.5 last:border-0 dark:border-gray-800">
                            <span>{{ $sync->type }} · {{ $sync->started_at?->diffForHumans() }}</span>
                            <span class="text-xs
                                {{ $sync->status === 'success' ? 'text-green-600' : ($sync->status === 'failed' ? 'text-red-600' : 'text-amber-600') }}">
                                {{ $sync->status }} ({{ $sync->items_processed }} ok, {{ $sync->items_failed }} failed)
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

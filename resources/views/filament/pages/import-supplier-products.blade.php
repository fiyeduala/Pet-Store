<x-filament-panels::page>
    @php $supplier = $this->supplier(); @endphp

    @if ($supplier?->isDemo())
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            <p class="font-semibold">Showing simulated catalogue data</p>
            <p>The supplier integration is in demo mode, so these are sample products, not the real
               supplier catalogue. Anything imported now is demo data. Switch the mode in
               Integrations once your credentials are in place.</p>
        </div>
    @endif

    <x-filament::section>
        <form wire:submit="search" class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <label for="keyword" class="mb-1 block text-sm font-medium">Search the supplier catalogue</label>
                <input id="keyword" type="search" wire:model="keyword" placeholder="e.g. rope toy"
                       class="block w-full rounded-lg border-gray-300 shadow-sm dark:border-gray-600 dark:bg-gray-900">
            </div>

            <label class="flex items-center gap-2 pb-2 text-sm">
                <input type="checkbox" wire:model="usStockOnly" class="rounded">
                Prefer items stocked in US warehouses
            </label>

            <x-filament::button type="submit" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="search">Search</span>
                <span wire:loading wire:target="search">Searching…</span>
            </x-filament::button>
        </form>

        <p class="mt-2 text-xs text-gray-500">
            Nothing is published by searching or importing. Every import lands as a draft for you to
            merchandise first. Stock and cost come from the supplier; anything you write is yours and
            will not be overwritten by a later sync.
        </p>
    </x-filament::section>

    @if ($searchError)
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            <p class="font-semibold">The supplier search failed</p>
            <p>{{ $searchError }}</p>
            <p class="mt-1 text-xs">No placeholder results are shown in place of a failed search.</p>
        </div>
    @endif

    <div wire:loading.flex wire:target="search,nextPage,previousPage" class="flex-col gap-3">
        @for ($i = 0; $i < 4; $i++)
            <div class="h-20 animate-pulse rounded-lg bg-gray-100 dark:bg-gray-800"></div>
        @endfor
    </div>

    <div wire:loading.remove wire:target="search,nextPage,previousPage">
        @if ($results !== [])
            <x-filament::section :heading="'Results (page '.$page.')'">
                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($results as $result)
                        <li class="flex items-center gap-4 py-3" wire:key="r-{{ $result['id'] }}">
                            <div class="size-14 shrink-0 overflow-hidden rounded bg-gray-100 dark:bg-gray-800">
                                @if ($result['image'])
                                    <img src="{{ $result['image'] }}" alt="" class="size-full object-cover" loading="lazy">
                                @endif
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium">{{ $result['name'] }}</p>
                                <p class="text-xs text-gray-500">
                                    {{ $result['category'] ?: 'Uncategorised' }} · supplier id {{ $result['id'] }}
                                </p>
                            </div>

                            @if ($result['already_imported'])
                                <x-filament::button
                                    tag="a"
                                    size="sm"
                                    color="gray"
                                    href="{{ \App\Filament\Resources\ProductResource::getUrl('edit', ['record' => $result['local_id']]) }}">
                                    Already imported — open
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    size="sm"
                                    wire:click="import('{{ $result['id'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="import('{{ $result['id'] }}')">
                                    <span wire:loading.remove wire:target="import('{{ $result['id'] }}')">Import as draft</span>
                                    <span wire:loading wire:target="import('{{ $result['id'] }}')">Importing…</span>
                                </x-filament::button>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="mt-4 flex items-center justify-between">
                    <x-filament::button color="gray" size="sm" wire:click="previousPage" :disabled="$page <= 1">
                        Previous
                    </x-filament::button>
                    <span class="text-sm text-gray-500">Page {{ $page }}</span>
                    <x-filament::button color="gray" size="sm" wire:click="nextPage" :disabled="! $hasMore">
                        Next
                    </x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($hasSearched && ! $searchError)
            <x-filament::section>
                <p class="text-sm text-gray-500">No products matched that search.</p>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>

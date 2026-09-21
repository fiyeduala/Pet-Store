@props(['title', 'items'])

<div class="space-y-3">
    <h2 class="text-sm font-semibold tracking-wide text-ink-900 uppercase">{{ $title }}</h2>
    <ul class="space-y-2 text-sm">
        @forelse ($items as $item)
            <li><a href="{{ $item->url }}" class="text-ink-500 hover:text-ink-900">{{ $item->label }}</a></li>
        @empty
            <li class="text-ink-400">Nothing here yet.</li>
        @endforelse
    </ul>
</div>

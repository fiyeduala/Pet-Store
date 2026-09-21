<button type="button"
        class="btn btn-ghost relative p-2"
        x-on:click="$dispatch('open-cart-drawer')"
        aria-label="Open basket, {{ $count }} {{ Str::plural('item', $count) }}">
    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M3 4.5h1.8l2.1 10.5a1.5 1.5 0 0 0 1.47 1.2h7.86a1.5 1.5 0 0 0 1.47-1.2L19.5 8H6"/>
        <circle cx="9.5" cy="19.5" r="1.25"/><circle cx="16.5" cy="19.5" r="1.25"/>
    </svg>
    @if ($count > 0)
        <span class="absolute -right-0.5 -top-0.5 flex size-5 items-center justify-center rounded-full text-[11px] font-semibold"
              style="background-color: var(--brand-accent); color: var(--brand-accent-contrast);"
              aria-hidden="true">{{ $count > 9 ? '9+' : $count }}</span>
    @endif
</button>

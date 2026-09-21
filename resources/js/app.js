import './bootstrap';
import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';

/*
 * Storefront interactivity.
 *
 * Livewire ships its own Alpine build, so we only register our own
 * instance when Livewire is not present on the page.
 */
Alpine.plugin(focus);

if (!window.Livewire) {
    window.Alpine = Alpine;
    Alpine.start();
}

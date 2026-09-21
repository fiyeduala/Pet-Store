<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Resolved store identity. One place the storefront, emails, metadata and
 * new invoices all read from, so a branding change propagates everywhere at
 * once. Historical order snapshots are written from here at order time and
 * are never rewritten afterwards.
 */
class Branding
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::rememberForever('petstore.branding', fn () => [
            'name' => $this->settings->string('brand.name', config('petstore.brand.name')),
            'tagline' => $this->settings->string('brand.tagline', config('petstore.brand.tagline')),
            'logo_light' => $this->settings->get('brand.logo_light'),
            'logo_dark' => $this->settings->get('brand.logo_dark'),
            'favicon' => $this->settings->get('brand.favicon'),
            'app_icon' => $this->settings->get('brand.app_icon'),
            'email_logo' => $this->settings->get('brand.email_logo'),
            'accent_color' => $this->settings->string('brand.accent_color', '#3E7C78'),
            'accent_contrast' => $this->settings->string('brand.accent_contrast', '#FFFFFF'),
            'support_email' => $this->settings->string('contact.support_email', ''),
            'support_phone' => $this->settings->string('contact.support_phone', ''),
            'support_hours' => $this->settings->string('contact.support_hours', ''),
            'business_name' => $this->settings->string('business.legal_name', ''),
            'business_address' => $this->settings->string('business.address', ''),
            'business_registration' => $this->settings->string('business.registration', ''),
            'social' => $this->settings->array('brand.social', []),
            'seo_title' => $this->settings->string('seo.default_title', ''),
            'seo_description' => $this->settings->string('seo.default_description', ''),
            'seo_image' => $this->settings->get('seo.default_image'),
        ]);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function name(): string
    {
        return $this->get('name') ?: 'Pet Store';
    }

    public function accentColor(): string
    {
        return $this->get('accent_color') ?: '#3E7C78';
    }

    /**
     * Public URL for a stored brand image, or null when nothing is uploaded.
     */
    public function imageUrl(string $key): ?string
    {
        $path = $this->get($key);

        if (! is_string($path) || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Immutable copy written onto an order so historical documents keep the
     * branding and business details that were in force when they were issued.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $all = $this->all();

        return [
            'name' => $all['name'],
            'tagline' => $all['tagline'],
            'accent_color' => $all['accent_color'],
            'logo_light' => $all['logo_light'],
            'email_logo' => $all['email_logo'],
            'support_email' => $all['support_email'],
            'support_phone' => $all['support_phone'],
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function businessSnapshot(): array
    {
        $all = $this->all();

        return [
            'legal_name' => $all['business_name'],
            'address' => $all['business_address'],
            'registration' => $all['business_registration'],
            'support_email' => $all['support_email'],
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    public function flush(): void
    {
        Cache::forget('petstore.branding');
    }
}

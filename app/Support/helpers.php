<?php

declare(strict_types=1);

use App\Domain\Settings\Branding;
use App\Domain\Settings\SettingsRepository;
use App\Support\Money\Money;

if (! function_exists('settings')) {
    /**
     * Read an owner-editable setting. Call with no arguments for the repository.
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $repository = app(SettingsRepository::class);

        if ($key === null) {
            return $repository;
        }

        return $repository->get($key, $default);
    }
}

if (! function_exists('branding')) {
    function branding(?string $key = null, mixed $default = null): mixed
    {
        $branding = app(Branding::class);

        if ($key === null) {
            return $branding;
        }

        return $branding->get($key, $default);
    }
}

if (! function_exists('money_minor')) {
    function money_minor(?int $minor, string $currency = 'USD'): ?Money
    {
        return $minor === null ? null : Money::ofMinor($minor, $currency);
    }
}

if (! function_exists('format_minor')) {
    /**
     * Format integer minor units for display, e.g. 1999 -> "$19.99".
     */
    function format_minor(?int $minor, string $currency = 'USD', string $nullLabel = '—'): string
    {
        if ($minor === null) {
            return $nullLabel;
        }

        return Money::ofMinor($minor, $currency)->format();
    }
}

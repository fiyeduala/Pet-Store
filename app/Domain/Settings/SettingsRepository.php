<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime, owner-editable settings.
 *
 * Reads are served from a single cached map so a page render costs one cache
 * hit rather than one query per lookup. Every write invalidates that map and
 * the derived caches (branding, navigation, home sections) that depend on it.
 */
class SettingsRepository
{
    public const CACHE_KEY = 'petstore.settings.all';

    /** Caches derived from settings that must be dropped on any write. */
    public const DEPENDENT_CACHE_KEYS = [
        'petstore.branding',
        'petstore.navigation.header',
        'petstore.navigation.footer',
        'petstore.home.sections',
    ];

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function all(): array
    {
        return $this->memo ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->pluck('value', 'key')->all()
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (! array_key_exists($key, $all)) {
            return $default;
        }

        $value = $all[$key];

        // Scalars round-trip through the JSON column as single-element arrays
        // in some drivers; unwrap so callers always see what they stored.
        return $value === null ? $default : $value;
    }

    public function set(string $key, mixed $value, string $group = 'general'): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group]
        );

        $this->flush();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();
        $this->flush();
    }

    public function boolean(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);

        foreach (self::DEPENDENT_CACHE_KEYS as $cacheKey) {
            Cache::forget($cacheKey);
        }
    }
}

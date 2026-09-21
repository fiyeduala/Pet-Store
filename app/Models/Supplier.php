<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class Supplier extends Model
{
    public const MODE_DEMO = 'demo';

    public const MODE_SANDBOX = 'sandbox';

    public const MODE_LIVE = 'live';

    protected $fillable = [
        'code', 'name', 'adapter', 'mode', 'is_enabled', 'settings',
        'connection_state', 'last_authenticated_at', 'token_expires_at',
        'last_checked_at', 'last_error', 'capabilities',
    ];

    /** Credentials are never mass-assignable and never serialised. */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'capabilities' => 'array',
            'last_authenticated_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SupplierSyncLog::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Decrypt the stored credential blob.
     *
     * @return array<string, mixed>
     */
    public function credentials(): array
    {
        if (blank($this->attributes['credentials'] ?? null)) {
            return [];
        }

        try {
            return (array) json_decode(Crypt::decryptString($this->attributes['credentials']), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // A credential blob we cannot decrypt (usually a changed APP_KEY)
            // must read as "no credentials", never as a partial set.
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->attributes['credentials'] = Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials(), $key, $default);
    }

    public function isLive(): bool
    {
        return $this->mode === self::MODE_LIVE;
    }

    public function isDemo(): bool
    {
        return $this->mode === self::MODE_DEMO;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * Whether a capability has been positively observed against this account.
     * Absent means "unverified", which callers must treat as unsupported.
     */
    public function supports(string $capability): bool
    {
        return (bool) data_get($this->capabilities ?? [], $capability, false);
    }

    public function recordCapability(string $capability, bool $supported): void
    {
        $capabilities = $this->capabilities ?? [];
        $capabilities[$capability] = $supported;
        $this->capabilities = $capabilities;
        $this->save();
    }

    public static function cj(): self
    {
        return once(fn () => static::query()->where('code', 'cjdropshipping')->firstOrFail());
    }
}

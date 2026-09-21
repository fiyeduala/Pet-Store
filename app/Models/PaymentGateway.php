<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentGateway extends Model
{
    public const MODE_DEMO = 'demo';
    public const MODE_SANDBOX = 'sandbox';
    public const MODE_LIVE = 'live';

    protected $fillable = [
        'code', 'name', 'is_enabled', 'mode', 'is_configured', 'is_verified',
        'verified_at', 'verified_by', 'verification_notes', 'supported_currencies',
        'public_client_id', 'settings', 'position', 'capability_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_configured' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'supported_currencies' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * A gateway may only take real money when it is enabled, in live mode,
     * has credentials, AND the owner has recorded that the merchant account
     * was verified with the provider. Sandbox success alone never suffices.
     */
    public function isLiveReady(): bool
    {
        return $this->is_enabled
            && $this->mode === self::MODE_LIVE
            && $this->is_configured
            && $this->is_verified;
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && ($this->mode !== self::MODE_LIVE || $this->isLiveReady());
    }

    public function supportsCurrency(string $currency): bool
    {
        $supported = array_map('strtoupper', $this->supported_currencies ?? []);

        return in_array(strtoupper($currency), $supported, true);
    }

    /**
     * Human-readable reason this gateway cannot be offered, or null if it can.
     */
    public function blockingReason(string $currency): ?string
    {
        if (! $this->is_enabled) {
            return 'Disabled by the store owner.';
        }

        if ($this->mode === self::MODE_LIVE && ! $this->is_configured) {
            return 'Live mode selected but no credentials are configured.';
        }

        if ($this->mode === self::MODE_LIVE && ! $this->is_verified) {
            return 'Live mode selected but the merchant account has not been recorded as verified.';
        }

        if (! $this->supportsCurrency($currency)) {
            return sprintf('This method is not configured to accept %s.', strtoupper($currency));
        }

        return null;
    }

    public function isDemo(): bool
    {
        return $this->mode === self::MODE_DEMO;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}

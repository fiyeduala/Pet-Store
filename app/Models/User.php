<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    public const ROLE_OWNER = 'owner';

    public const ROLE_OPERATIONS = 'operations';

    public const ROLE_SUPPORT = 'support';

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'is_staff', 'accepts_marketing', 'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'accepts_marketing' => 'boolean',
            // TOTP material is encrypted at rest.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultShippingAddress(): ?Address
    {
        return $this->addresses()->where('is_default_shipping', true)->first()
            ?? $this->addresses()->first();
    }

    /**
     * Only staff accounts may open the admin panel. Being an authenticated
     * customer is never sufficient.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_staff
            && $this->deleted_at === null
            && $this->hasAnyRole([self::ROLE_OWNER, self::ROLE_OPERATIONS, self::ROLE_SUPPORT]);
    }

    public function isOwner(): bool
    {
        return $this->hasRole(self::ROLE_OWNER);
    }

    /* ---- Filament multi-factor (TOTP) ---- */

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Settings\Branding;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The administration panel.
 *
 * Filament is the admin interface only. It never renders the storefront,
 * which is plain Blade + Livewire with its own design system.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // TOTP second factor, available to every staff account and
            // required for anyone who can move money once enabled in settings.
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable()
                    ->regenerableRecoveryCodes(),
            ], isRequired: (bool) config('petstore.admin.require_mfa', false))
            ->passwordReset()
            ->profile(isSimple: false)
            ->emailVerification()
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Slate,
            ])
            ->defaultThemeMode(ThemeMode::Light)
            ->brandName(fn () => app(Branding::class)->name().' admin')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // An explicit dashboard, so /admin renders rather than trying to
            // redirect to whichever resource happens to be first.
            ->pages([Dashboard::class])
            ->navigationGroups([
                NavigationGroup::make('Catalogue'),
                NavigationGroup::make('Orders'),
                NavigationGroup::make('Money'),
                NavigationGroup::make('Fulfilment'),
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Content'),
                NavigationGroup::make('Configuration'),
                NavigationGroup::make('System'),
            ])
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\TradingOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

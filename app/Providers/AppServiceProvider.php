<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Settings\Branding;
use App\Domain\Settings\SettingsRepository;
use App\Domain\Shared\ModeGuard;
use App\Domain\Tax\ManualTaxProvider;
use App\Domain\Tax\TaxCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(Branding::class);
        $this->app->singleton(ModeGuard::class);
        $this->app->singleton(ManualTaxProvider::class);

        $this->app->singleton(TaxCalculator::class, fn ($app) => new TaxCalculator(
            $app->make(ManualTaxProvider::class),
            // Register additional providers here as they are integrated.
            providers: [],
        ));
    }

    public function boot(): void
    {
        // Fail loudly in development when a relation was not eager loaded or
        // a fillable attribute was missed, rather than shipping an N+1.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Money columns are unsigned-safe integers; never let Eloquent
        // coerce them through a float.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}

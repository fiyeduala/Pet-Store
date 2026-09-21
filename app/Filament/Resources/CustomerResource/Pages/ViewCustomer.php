<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected string $view = 'filament.pages.view-customer';

    /** Renders a custom Blade view, so there is no form state to fill. */
    protected function fillForm(): void
    {
        // Intentionally empty.
    }
}

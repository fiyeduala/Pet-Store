<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\SupplierResource;
use App\Models\Supplier;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    /**
     * Credentials are written through the model's encrypting setter and are
     * never bound as ordinary form fields, so they cannot leak into a
     * Livewire payload or a validation error.
     */
    protected function afterSave(): void
    {
        /** @var Supplier $supplier */
        $supplier = $this->record;
        $state = $this->form->getRawState();

        $email = $state['credential_email'] ?? null;
        $apiKey = $state['credential_api_key'] ?? null;

        $credentials = $supplier->credentials();

        if (filled($email)) {
            $credentials['email'] = $email;
        }

        // Blank means "keep what is saved", so an operator editing another
        // field cannot wipe the key by accident.
        if (filled($apiKey)) {
            $credentials['api_key'] = $apiKey;
        }

        if ($credentials !== $supplier->credentials()) {
            $supplier->setCredentials($credentials);
            $supplier->save();

            Notification::make()->success()->title('Credentials updated')->send();
        }

        if ($supplier->mode === Supplier::MODE_LIVE && blank($supplier->credential('api_key'))) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title('Live mode without credentials')
                ->body('This integration is set to live but has no API key. Live calls will fail until one is saved.')
                ->send();
        }
    }
}

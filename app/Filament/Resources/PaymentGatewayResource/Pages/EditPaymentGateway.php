<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentGatewayResource\Pages;

use App\Filament\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPaymentGateway extends EditRecord
{
    protected static string $resource = PaymentGatewayResource::class;

    /**
     * Refuse to enable a live method that cannot legitimately take money.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $goingLive = ($data['mode'] ?? null) === PaymentGateway::MODE_LIVE && ($data['is_enabled'] ?? false);

        if ($goingLive && ! ($this->record->is_configured)) {
            $data['is_enabled'] = false;

            Notification::make()->danger()->persistent()
                ->title('Not enabled')
                ->body('This method has no credentials on the server. Add them to .env first.')
                ->send();
        } elseif ($goingLive && ! ($data['is_verified'] ?? false)) {
            $data['is_enabled'] = false;

            Notification::make()->danger()->persistent()
                ->title('Not enabled')
                ->body('Live mode requires you to record that the merchant account was verified with the provider. Sandbox success is not enough.')
                ->send();
        }

        return $data;
    }
}

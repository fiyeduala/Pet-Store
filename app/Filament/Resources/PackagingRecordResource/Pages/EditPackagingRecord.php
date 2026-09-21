<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackagingRecordResource\Pages;

use App\Filament\Resources\PackagingRecordResource;
use App\Models\PackagingRecord;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPackagingRecord extends EditRecord
{
    protected static string $resource = PackagingRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Marking packaging available is a factual claim about warehouse stock,
     * so it is refused unless a quantity backs it up.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $available = ($data['state'] ?? null) === PackagingRecord::STATE_AVAILABLE;
        $quantity = (int) ($data['available_quantity'] ?? 0);

        if ($available && $quantity <= 0) {
            $data['state'] = PackagingRecord::STATE_APPROVED;

            Notification::make()
                ->warning()
                ->persistent()
                ->title('Not marked available')
                ->body('Packaging cannot be "available" with no confirmed quantity at the warehouse. Saved as "approved" instead.')
                ->send();
        }

        return $data;
    }
}

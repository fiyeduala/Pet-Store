<?php

declare(strict_types=1);

namespace App\Filament\Resources\PetTypeResource\Pages;

use App\Filament\Resources\PetTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPetTypes extends ListRecords
{
    protected static string $resource = PetTypeResource::class;

    protected function getHeaderActions(): array
    {
        return PetTypeResource::canCreate() ? [CreateAction::make()] : [];
    }
}

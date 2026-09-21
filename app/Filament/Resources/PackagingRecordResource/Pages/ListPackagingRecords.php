<?php

declare(strict_types=1);

namespace App\Filament\Resources\PackagingRecordResource\Pages;

use App\Filament\Resources\PackagingRecordResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackagingRecords extends ListRecords
{
    protected static string $resource = PackagingRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}

<?php

namespace App\Filament\Resources\Demurrages\Pages;

use App\Enums\ContainerTime;
use App\Filament\Resources\Demurrages\DemurrageResource;
use App\Filament\Support\ContainerTimeTable;
use Filament\Resources\Pages\ListRecords;

class ListDemurrages extends ListRecords
{
    protected static string $resource = DemurrageResource::class;

    public function getTabs(): array
    {
        return ContainerTimeTable::tabs(ContainerTime::Demurrage);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}

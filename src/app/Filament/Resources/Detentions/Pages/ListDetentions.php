<?php

namespace App\Filament\Resources\Detentions\Pages;

use App\Enums\ContainerTime;
use App\Filament\Resources\Detentions\DetentionResource;
use App\Filament\Support\ContainerTimeTable;
use Filament\Resources\Pages\ListRecords;

class ListDetentions extends ListRecords
{
    protected static string $resource = DetentionResource::class;

    public function getTabs(): array
    {
        return ContainerTimeTable::tabs(ContainerTime::Detention);
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}

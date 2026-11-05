<?php

namespace App\Filament\Resources\CompanyGroups\Pages;

use App\Filament\Resources\CompanyGroups\CompanyGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyGroups extends ListRecords
{
    protected static string $resource = CompanyGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Grupo'),
        ];
    }
}

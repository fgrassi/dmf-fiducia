<?php

namespace App\Filament\Resources\InsuranceCompanies\Pages;

use App\Filament\Resources\InsuranceCompanies\InsuranceCompanyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInsuranceCompanies extends ManageRecords
{
    protected static string $resource = InsuranceCompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Cia Seguro'),
        ];
    }
}

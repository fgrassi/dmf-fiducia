<?php

namespace App\Filament\Resources\CompanyRates\Pages;

use App\Filament\Resources\CompanyRates\CompanyRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyRates extends ManageRecords
{
    protected static string $resource = CompanyRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir Nova Taxa Negociada'),
        ];
    }
}

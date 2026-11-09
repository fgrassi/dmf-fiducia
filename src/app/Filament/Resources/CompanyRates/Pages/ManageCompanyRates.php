<?php

namespace App\Filament\Resources\CompanyRates\Pages;

use App\Actions\Financeiro\CompanyRatesPdf;
use App\Filament\Resources\CompanyRates\CompanyRateResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyRates extends ManageRecords
{
    protected static string $resource = CompanyRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Imprimir Relação')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->action(fn () => app(CompanyRatesPdf::class)()),
            CreateAction::make()
                ->label('Incluir Nova Taxa Negociada'),
        ];
    }
}

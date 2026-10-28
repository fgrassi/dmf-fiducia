<?php

namespace App\Filament\Resources\CurrencyRates\Pages;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CurrencyRates\CurrencyRateResource;
use App\Models\FinCurrenciesRates;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCurrencyRates extends ManageRecords
{
    protected static string $resource = CurrencyRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RateValueAction::forAny(SaveRateValue::CURRENCY, FinCurrenciesRates::with('currency')->orderBy('name')->get()->pluck('label', 'id')->all()),
            CreateAction::make()
                ->label('Incluir Nova Cotação'),
        ];
    }
}

<?php

namespace App\Filament\Resources\CarrierRates\Pages;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CarrierRates\CarrierRateResource;
use App\Models\FinCurrenciesRatesArmador;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCarrierRates extends ManageRecords
{
    protected static string $resource = CarrierRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RateValueAction::forAny(SaveRateValue::CARRIER, FinCurrenciesRatesArmador::with(['armador', 'currency'])->get()->pluck('label', 'id')->all()),
            CreateAction::make()
                ->label('Incluir Nova Cotação de Armador'),
        ];
    }
}

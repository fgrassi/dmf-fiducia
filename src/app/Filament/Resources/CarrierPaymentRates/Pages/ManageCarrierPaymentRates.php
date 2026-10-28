<?php

namespace App\Filament\Resources\CarrierPaymentRates\Pages;

use App\Actions\CurrencyRates\SaveRateValue;
use App\Filament\Actions\RateValueAction;
use App\Filament\Resources\CarrierPaymentRates\CarrierPaymentRateResource;
use App\Models\FinCurrenciesRatesArmadorPayment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCarrierPaymentRates extends ManageRecords
{
    protected static string $resource = CarrierPaymentRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RateValueAction::forAny(SaveRateValue::CARRIER_PAYMENT, FinCurrenciesRatesArmadorPayment::with(['armador', 'currency'])->get()->pluck('label', 'id')->all()),
            CreateAction::make()
                ->label('Incluir Nova Cotação de Pagamento'),
        ];
    }
}

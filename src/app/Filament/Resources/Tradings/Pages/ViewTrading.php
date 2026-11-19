<?php

namespace App\Filament\Resources\Tradings\Pages;

use App\Filament\Resources\Tradings\TradingResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTrading extends ViewRecord
{
    protected static string $resource = TradingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

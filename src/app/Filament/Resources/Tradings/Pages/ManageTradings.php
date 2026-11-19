<?php

namespace App\Filament\Resources\Tradings\Pages;

use App\Filament\Resources\Tradings\TradingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTradings extends ManageRecords
{
    protected static string $resource = TradingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Trading')
                ->mutateDataUsing(fn (array $data) => [...$data, 'dtecadastro' => now()->toDateString()]),
        ];
    }
}

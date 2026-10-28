<?php

namespace App\Filament\Resources\FinancialContacts\Pages;

use App\Filament\Resources\FinancialContacts\FinancialContactResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageFinancialContacts extends ManageRecords
{
    protected static string $resource = FinancialContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar Contato')
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now(), 'created_by' => auth()->id()]),
        ];
    }
}

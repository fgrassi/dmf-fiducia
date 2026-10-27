<?php

namespace App\Filament\Resources\ClientExceptions\Pages;

use App\Filament\Resources\ClientExceptions\ClientExceptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClientExceptions extends ManageRecords
{
    protected static string $resource = ClientExceptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar Cliente')
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now(), 'created_by' => auth()->id()]),
        ];
    }
}

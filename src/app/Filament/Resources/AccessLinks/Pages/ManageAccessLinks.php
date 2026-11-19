<?php

namespace App\Filament\Resources\AccessLinks\Pages;

use App\Filament\Resources\AccessLinks\AccessLinkResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAccessLinks extends ManageRecords
{
    protected static string $resource = AccessLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Novo Link')
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now(), 'created_by' => auth()->id()]),
        ];
    }
}

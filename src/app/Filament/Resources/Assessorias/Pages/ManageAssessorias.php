<?php

namespace App\Filament\Resources\Assessorias\Pages;

use App\Filament\Resources\Assessorias\AssessoriaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAssessorias extends ManageRecords
{
    protected static string $resource = AssessoriaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova Assessoria')
                ->mutateDataUsing(fn (array $data) => [...$data, 'dtecadastro' => now()->toDateString()]),
        ];
    }
}

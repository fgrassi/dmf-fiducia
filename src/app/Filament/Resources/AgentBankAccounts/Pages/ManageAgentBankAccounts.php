<?php

namespace App\Filament\Resources\AgentBankAccounts\Pages;

use App\Filament\Resources\AgentBankAccounts\AgentBankAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAgentBankAccounts extends ManageRecords
{
    protected static string $resource = AgentBankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Incluir nova conta')
                ->modalWidth('4xl'),
        ];
    }
}

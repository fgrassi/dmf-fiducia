<?php

namespace App\Filament\Resources\NfseRules\Pages;

use App\Actions\Cobranca\NfseRules\SaveNfseRule;
use App\Filament\Resources\NfseRules\NfseRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageNfseRules extends ManageRecords
{
    protected static string $resource = NfseRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Adicionar regra')
                ->using(fn (array $data) => app(SaveNfseRule::class)($data, auth()->id())),
        ];
    }
}

<?php

namespace App\Filament\Resources\NfseOperations\Pages;

use App\Filament\Resources\NfseOperations\NfseOperationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNfseOperation extends CreateRecord
{
    protected static string $resource = NfseOperationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'ref_config_nfse' => 1];
    }

    /** updateOperacao (legado): após salvar segue na edição, onde as despesas são vinculadas. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Operação NFSe salva com sucesso.';
    }
}

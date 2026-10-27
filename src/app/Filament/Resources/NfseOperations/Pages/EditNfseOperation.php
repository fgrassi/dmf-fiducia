<?php

namespace App\Filament\Resources\NfseOperations\Pages;

use App\Filament\Resources\NfseOperations\NfseOperationResource;
use Filament\Resources\Pages\EditRecord;

class EditNfseOperation extends EditRecord
{
    protected static string $resource = NfseOperationResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Operação NFSe salva com sucesso.';
    }
}

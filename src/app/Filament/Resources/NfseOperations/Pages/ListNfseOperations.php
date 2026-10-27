<?php

namespace App\Filament\Resources\NfseOperations\Pages;

use App\Filament\Pages\NfseSettings;
use App\Filament\Resources\NfseOperations\NfseOperationResource;
use Filament\Resources\Pages\ListRecords;

/** A listagem vive na página Configuração NFS-e; esta rota (breadcrumb das telas de operação) só redireciona para lá. */
class ListNfseOperations extends ListRecords
{
    protected static string $resource = NfseOperationResource::class;

    public function mount(): void
    {
        $this->redirect(NfseSettings::getUrl());
    }
}

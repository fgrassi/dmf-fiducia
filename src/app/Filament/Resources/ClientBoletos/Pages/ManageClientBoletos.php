<?php

namespace App\Filament\Resources\ClientBoletos\Pages;

use App\Actions\ClientBoletos\ExportClientBoletos;
use App\Filament\Resources\ClientBoletos\ClientBoletoResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageClientBoletos extends ManageRecords
{
    protected static string $resource = ClientBoletoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportar')
                ->label('Exportar para Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => app(ExportClientBoletos::class)()),
            CreateAction::make()
                ->label('Adicionar Negociação'),
        ];
    }
}

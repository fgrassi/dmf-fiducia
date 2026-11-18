<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Filament\Resources\Embarques\EmbarqueResource;
use App\Filament\Resources\Embarques\Tables\InsuranceBookingsTable;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

/** Lista de Seguros (seguro/list do legado): lista própria das reservas de seguro, com as colunas do legado. */
class ListSeguros extends ListRecords
{
    protected static string $resource = EmbarqueResource::class;

    protected static ?string $title = 'Lista de Seguros';

    public function table(Table $table): Table
    {
        return InsuranceBookingsTable::configure($table);
    }
}

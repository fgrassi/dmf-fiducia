<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Filament\Resources\Embarques\EmbarqueResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmbarques extends ListRecords
{
    protected static string $resource = EmbarqueResource::class;

    /** Lista de bookings do legado: sem seguros (têm lista própria) e sem reservas sem tipo. */
    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->inBookingList();
    }

    /** Abas por operação (impor_expor): i = Importação, e = Exportação. */
    public function getTabs(): array
    {
        return [
            'todas' => Tab::make('Todas'),
            'impo' => Tab::make('Impo')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('impor_expor', 'i')),
            'expo' => Tab::make('Expo')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('impor_expor', 'e')),
        ];
    }
}

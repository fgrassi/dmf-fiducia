<?php

namespace App\Filament\Resources\ControlePas\Pages;

use App\Filament\Resources\ControlePas\ControlePaResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListControlePas extends ListRecords
{
    protected static string $resource = ControlePaResource::class;

    /** Abas Abertos / Fechados do legado; só os abertos cortam as reservas com ETD previsto antes de 2020. */
    public function getTabs(): array
    {
        return [
            'abertos' => Tab::make('Abertos')
                ->modifyQueryUsing(fn (Builder $query) => $query->pending()->where('embarque.saida_prevista', '>=', '2020-01-01')),
            'fechados' => Tab::make('Fechados')
                ->modifyQueryUsing(fn (Builder $query) => $query->completed()),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}

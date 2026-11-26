<?php

namespace App\Filament\Resources\Embarques\Pages;

use App\Filament\Resources\Embarques\EmbarqueResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListEmbarques extends ListRecords
{
    protected static string $resource = EmbarqueResource::class;

    /** Menu "Reservas" do legado: os quatro tipos de reserva nova, só das operações que o usuário enxerga. */
    protected function getHeaderActions(): array
    {
        $only = auth()->user()->onlyOperation();

        return [
            ActionGroup::make(collect([['i', 'm'], ['e', 'm'], ['i', 'a'], ['e', 'a']])
                ->filter(fn (array $type) => ! $only || $only === $type[0])
                ->map(fn (array $type) => Action::make("nova_{$type[0]}{$type[1]}")
                    ->label(CreateEmbarque::label(...$type))
                    ->url(EmbarqueResource::getUrl('create', ['operacao' => $type[0], 'tipo' => $type[1]])))
                ->values()
                ->all())
                ->label('Nova reserva')
                ->icon(Heroicon::OutlinedPlus)
                ->button(),
        ];
    }

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

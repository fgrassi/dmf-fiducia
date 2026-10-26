<?php

namespace App\Filament\Resources\Charges\Pages;

use App\Actions\Cobranca\Listing\BuildChargeListQuery;
use App\Filament\Resources\Charges\ChargeResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    /** "Exibir" do legado: Abertos (padrão) / Pagos / Todos. */
    public function getTabs(): array
    {
        $show = fn (string $mode) => fn (Builder $query) => app(BuildChargeListQuery::class)->applyShow($query, $mode);

        return [
            'abertos' => Tab::make('Abertos')->modifyQueryUsing($show('abertos')),
            'pagos' => Tab::make('Pagos')->modifyQueryUsing($show('pagos')),
            'todos' => Tab::make('Todos'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'abertos';
    }
}

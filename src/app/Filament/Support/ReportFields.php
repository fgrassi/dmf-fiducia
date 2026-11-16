<?php

namespace App\Filament\Support;

use App\Models\Armador;
use App\Models\Navio;
use App\Models\Pais;
use App\Models\Pessoa;
use App\Models\Porto;
use App\Models\Vendedor;
use Filament\Forms\Components\Select;

/** Filtros de busca dos relatórios (os autocompletes do legado): pessoa, porto, país, navio, armador e vendedor. */
class ReportFields
{
    public static function person(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Pessoa::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'pessoa_id'))
            ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'));
    }

    public static function port(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Porto::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'porto_id'))
            ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome'));
    }

    public static function country(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn () => Pais::query()->orderBy('nome')->pluck('nome', 'paises_id'));
    }

    public static function ship(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Navio::query()->where('nome', 'like', "%{$search}%")->orderBy('nome')->limit(30)->pluck('nome', 'navio_id'))
            ->getOptionLabelUsing(fn ($value) => Navio::query()->whereKey($value)->value('nome'));
    }

    public static function carrier(string $name, string $label = 'Armador'): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn () => Armador::query()->orderBy('nome')->pluck('nome', 'armador_id'));
    }

    public static function seller(string $name, string $label = 'Vendedor'): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->options(fn () => Vendedor::query()->whereNull('deleted_at')->orderBy('nome')->pluck('nome', 'vendedor_id'));
    }
}

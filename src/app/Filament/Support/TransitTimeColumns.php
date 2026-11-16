<?php

namespace App\Filament\Support;

use Closure;
use Filament\Tables\Columns\TextColumn;

/** Colunas comuns dos dois relatórios de Transit Time (armador e destino). */
class TransitTimeColumns
{
    public const HEADERS = ['Armador', 'Qtd. Embarque', 'País Origem', 'Origem', 'País Destino', 'Destino', 'TT médio previsto', 'TT médio efetivo', 'Atraso'];

    /** @return array<int, TextColumn> */
    public static function make(string $plannedLabel, string $originLabel, string $destinationLabel, bool|Closure $showCount = true): array
    {
        $days = fn ($state) => number_format((float) $state, 2, ',', '.');

        return [
            TextColumn::make('carrier')
                ->label('Armador')
                ->wrap()
                ->sortable(),
            TextColumn::make('count')
                ->label('Qtd. Embarque')
                ->sortable()
                ->visible($showCount),
            TextColumn::make('originCountry')
                ->label('País Origem')
                ->wrap()
                ->sortable(),
            TextColumn::make('origin')
                ->label($originLabel)
                ->sortable(),
            TextColumn::make('destinationCountry')
                ->label('País Destino')
                ->wrap()
                ->sortable(),
            TextColumn::make('destination')
                ->label($destinationLabel)
                ->sortable(),
            TextColumn::make('planned')
                ->label($plannedLabel)
                ->alignEnd()
                ->sortable()
                ->formatStateUsing($days),
            TextColumn::make('actual')
                ->label('TT Efetivo (média)')
                ->alignEnd()
                ->sortable()
                ->formatStateUsing($days),
            TextColumn::make('delay')
                ->label('Atraso')
                ->alignEnd()
                ->sortable()
                ->formatStateUsing($days),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<scalar>>
     */
    public static function sheetRows(array $rows): array
    {
        return array_map(fn (array $row) => [$row['carrier'], $row['count'], $row['originCountry'], $row['origin'], $row['destinationCountry'], $row['destination'], round($row['planned'], 2), round($row['actual'], 2), round($row['delay'], 2)], $rows);
    }
}

<?php

namespace App\Filament\Actions;

use App\Actions\Cobranca\Nfse\AddressLookup;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SearchCityAction
{
    /** "Buscar (IBGE)" do legado: município pelo nome (sem acentos), UF opcional; preenche o código IBGE e, se informado, a UF. */
    public static function make(string $codeField, ?string $ufField = null): Action
    {
        return Action::make('searchCity')
            ->label('Buscar (IBGE)')
            ->icon('heroicon-o-magnifying-glass')
            ->modalHeading('Buscar município (IBGE)')
            ->modalWidth('md')
            ->schema([
                TextInput::make('uf')
                    ->label('UF (opcional)')
                    ->maxLength(2)
                    ->live(onBlur: true),
                Select::make('city')
                    ->label('Município')
                    ->placeholder('Nome da cidade (mín. 2 letras)')
                    ->searchable()
                    ->searchDebounce(350)
                    ->required()
                    ->getOptionLabelUsing(fn ($value) => $value)
                    ->getSearchResultsUsing(fn (string $search, Get $get) => collect(app(AddressLookup::class)->searchCities($search, (string) $get('uf')))
                        ->mapWithKeys(fn (array $c) => [$c['id'] => $c['nome'].($c['uf'] ? " / {$c['uf']}" : '')." ({$c['id']})"])
                        ->all()),
            ])
            ->action(function (array $data, Set $set) use ($codeField, $ufField) {
                $set($codeField, $data['city']);

                if ($ufField && $uf = app(AddressLookup::class)->city((string) $data['city'])['uf'] ?? null) {
                    $set($ufField, $uf);
                }
            });
    }
}

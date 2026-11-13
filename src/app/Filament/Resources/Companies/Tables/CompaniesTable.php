<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Actions\Companies\DeleteCompany;
use App\Filament\Actions\MergeRecordsAction;
use App\Models\Pessoa;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->addSelect([
                'pessoa.*',
                'cidade_nome' => DB::table('cidades')->select('nome')->whereColumn('cidades.cidades_id', 'pessoa.cidades_id'),
                'sede_nome' => DB::table('sedes')
                    ->join('pessoa_sede', 'pessoa_sede.sede_id', '=', 'sedes.id')
                    ->select('sedes.nome')
                    ->whereColumn('pessoa_sede.pessoa_id', 'pessoa.pessoa_id')
                    ->limit(1),
            ]))
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('pessoa_id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('cidade_nome')
                    ->label('Cidade')
                    ->sortable(),
                TextColumn::make('sede_nome')
                    ->label('Escritório Comercial')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('sede')
                    ->label('Escritório Comercial')
                    ->options(fn () => DB::table('sedes')->orderBy('nome')->pluck('nome', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'],
                        fn (Builder $query, $sedeId) => $query->whereIn('pessoa.pessoa_id', DB::table('pessoa_sede')->select('pessoa_id')->where('sede_id', $sedeId)),
                    )),
                Filter::make('local')
                    ->schema([
                        Select::make('estados_id')
                            ->label('Estado')
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('cidades_id', null))
                            ->options(fn () => DB::table('estados')->orderBy('nome')->pluck('nome', 'estados_id')),
                        Select::make('cidades_id')
                            ->label('Cidade')
                            ->searchable()
                            ->options(fn (Get $get) => DB::table('cidades')->where('estados_id', $get('estados_id'))->orderBy('nome')->pluck('nome', 'cidades_id')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['cidades_id'], fn (Builder $query, $cityId) => $query->where('pessoa.cidades_id', $cityId))
                        ->when($data['estados_id'], fn (Builder $query, $stateId) => $query->whereIn('pessoa.cidades_id', DB::table('cidades')->select('cidades_id')->where('estados_id', $stateId)))),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->using(fn (Pessoa $record) => app(DeleteCompany::class)($record))
                    ->failureNotificationTitle('Empresa em uso por outros registros: não pode ser excluída'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    MergeRecordsAction::make('company'),
                ]),
            ]);
    }
}

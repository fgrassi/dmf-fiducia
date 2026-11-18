<?php

namespace App\Filament\Resources\Embarques\Tables;

use App\Enums\OperationType;
use App\Models\Embarque;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista de Seguros (seguro/list do legado): as reservas de seguro em quatro colunas empilhadas, como no legado (reserva, datas,
 * partes e rota), com os filtros Hoje / Esta Semana / Este Mês, data de solicitação e cliente.
 */
class InsuranceBookingsTable
{
    private const PERIODS = ['hoje' => 'Hoje', 'semana' => 'Esta Semana', 'mes' => 'Este Mês'];

    public static function configure(Table $table): Table
    {
        $date = fn (?string $value) => $value ? Carbon::parse($value)->format('d/m/Y') : '';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->where('tipo_embarque', 's')->with(['exportador', 'importador', 'agente', 'portoOrigem', 'portoDestino']))
            ->defaultSort('data_topo', 'desc')
            ->columns([
                TextColumn::make('reserva')
                    ->label('Reserva')
                    ->state(fn (Embarque $record) => array_filter([
                        $record->booking,
                        OperationType::labelFor($record->impor_expor),
                        Embarque::TYPES[$record->tipo_embarque] ?? null,
                        Embarque::STATUSES[$record->finalizado] ?? null,
                        $record->invoice,
                        $record->customer_ref,
                    ], filled(...)))
                    ->listWithLineBreaks()
                    ->searchable(['booking', 'invoice', 'customer_ref', 'house_bl', 'master_bl'])
                    ->sortable(['booking']),
                TextColumn::make('datas')
                    ->label('Datas')
                    ->state(fn (Embarque $record) => [
                        'DATA: '.$date($record->data_topo),
                        'ETD: '.$date($record->saida_prevista),
                        'ETA: '.$date($record->chegada_prevista),
                    ])
                    ->listWithLineBreaks()
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('data_topo', $direction)),
                TextColumn::make('partes')
                    ->label('Partes')
                    ->state(fn (Embarque $record) => [
                        'Exportador: '.$record->exportador?->nome,
                        'Importador: '.$record->importador?->nome,
                        'Agente: '.$record->agente?->nome,
                    ])
                    ->listWithLineBreaks()
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query
                        ->whereHas('exportador', fn (Builder $party) => $party->where('nome', 'like', "%{$search}%"))
                        ->orWhereHas('importador', fn (Builder $party) => $party->where('nome', 'like', "%{$search}%"))
                        ->orWhereHas('agente', fn (Builder $party) => $party->where('nome', 'like', "%{$search}%")))),
                TextColumn::make('rota')
                    ->label('Rota')
                    ->state(fn (Embarque $record) => [
                        'Origem: '.$record->portoOrigem?->nome,
                        'Destino: '.$record->portoDestino?->nome,
                        'Via: '.$record->via,
                    ])
                    ->listWithLineBreaks(),
            ])
            ->filters([
                SelectFilter::make('exibir')
                    ->label('Exibir')
                    ->options(self::PERIODS)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'hoje' => $query->whereDate('data_topo', today()),
                        'semana' => $query->whereDate('data_topo', '>=', today()->startOfWeek()),
                        'mes' => $query->whereDate('data_topo', '>=', today()->startOfMonth()),
                        default => $query,
                    }),
                Filter::make('data_topo')
                    ->label('Data de solicitação')
                    ->schema([DatePicker::make('data_topo')->label('Data de solicitação')])
                    ->query(fn (Builder $query, array $data) => $query->when($data['data_topo'] ?? null, fn (Builder $query, string $day) => $query->whereDate('data_topo', $day))),
                SelectFilter::make('cliente_id')
                    ->label('Cliente')
                    ->relationship('cliente', 'nome')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

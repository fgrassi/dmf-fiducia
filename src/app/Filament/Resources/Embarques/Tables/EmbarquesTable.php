<?php

namespace App\Filament\Resources\Embarques\Tables;

use App\Enums\OperationType;
use App\Filament\Support\OperationBadge;
use App\Models\Embarque;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class EmbarquesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('embarque_id', 'desc')
            ->columns([
                TextColumn::make('booking')
                    ->label('Reserva')
                    ->searchable()
                    ->sortable(),
                OperationBadge::column()
                    ->label('Operação'),
                TextColumn::make('tipo_embarque')
                    ->label('Modal')
                    ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                TextColumn::make('cliente.nome')
                    ->label('Cliente')
                    ->searchable(),
                TextColumn::make('armador.nome')
                    ->label('Armador'),
                TextColumn::make('house_bl')
                    ->label('HBL')
                    ->searchable(),
                TextColumn::make('master_bl')
                    ->label('MBL')
                    ->searchable(),
                TextColumn::make('saida_prevista')
                    ->label('Saída prevista')
                    ->date('d/m/Y'),
                TextColumn::make('finalizado')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state),
            ])
            ->filters([
                SelectFilter::make('impor_expor')
                    ->label('Operação')
                    ->options(OperationType::options()),
                SelectFilter::make('tipo_embarque')
                    ->label('Modal')
                    ->options(Embarque::TYPES),
                SelectFilter::make('finalizado')
                    ->label('Status')
                    ->multiple()
                    ->options(Embarque::STATUSES),
                self::dateRange('saida_prevista', 'Saída prevista'),
                self::dateRange('chegada_prevista', 'Chegada prevista'),
                self::dateRange('dteCadastro', 'Cadastro'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    /** Filtro "de/até" por uma coluna de data da reserva (o dashboard abre a lista com ele já preenchido). */
    private static function dateRange(string $column, string $label): Filter
    {
        return Filter::make($column)
            ->label($label)
            ->columns(2)
            ->schema([
                DatePicker::make('from')
                    ->label($label.' de'),
                DatePicker::make('until')
                    ->label($label.' até'),
            ])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate($column, '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate($column, '<=', $date)))
            ->indicateUsing(fn (array $data) => array_filter([
                ($data['from'] ?? null) ? $label.' de '.Carbon::parse($data['from'])->format('d/m/Y') : null,
                ($data['until'] ?? null) ? $label.' até '.Carbon::parse($data['until'])->format('d/m/Y') : null,
            ]));
    }
}

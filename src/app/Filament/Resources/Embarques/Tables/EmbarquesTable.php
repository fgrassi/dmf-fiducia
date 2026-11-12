<?php

namespace App\Filament\Resources\Embarques\Tables;

use App\Enums\OperationType;
use App\Filament\Support\OperationBadge;
use App\Models\Embarque;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                    ->options(Embarque::STATUSES),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

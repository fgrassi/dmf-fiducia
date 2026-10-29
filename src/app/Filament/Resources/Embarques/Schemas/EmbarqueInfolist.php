<?php

namespace App\Filament\Resources\Embarques\Schemas;

use App\Models\Embarque;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmbarqueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reserva')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('booking')
                            ->label('Reserva'),
                        TextEntry::make('impor_expor')
                            ->label('Operação')
                            ->formatStateUsing(fn (?string $state) => Embarque::OPERATIONS[$state] ?? $state),
                        TextEntry::make('tipo_embarque')
                            ->label('Modal')
                            ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                        TextEntry::make('cliente.nome')
                            ->label('Cliente')
                            ->placeholder('Não informado'),
                        TextEntry::make('importador.nome')
                            ->label('Importador')
                            ->placeholder('Não informado'),
                        TextEntry::make('armador.nome')
                            ->label('Armador')
                            ->placeholder('Não informado'),
                        TextEntry::make('house_bl')
                            ->label('HBL')
                            ->placeholder('Não informado'),
                        TextEntry::make('master_bl')
                            ->label('MBL')
                            ->placeholder('Não informado'),
                        TextEntry::make('invoice')
                            ->label('Invoice')
                            ->placeholder('Não informado'),
                        TextEntry::make('saida_prevista')
                            ->label('Saída prevista')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('chegada_prevista')
                            ->label('Chegada prevista')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('finalizado')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state),
                        TextEntry::make('ctr_desp')
                            ->label('Despesas pendentes de cobrança')
                            ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Sim' : 'Não'),
                    ]),
            ]);
    }
}

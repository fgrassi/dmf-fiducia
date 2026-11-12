<?php

namespace App\Filament\Resources\Embarques\Schemas;

use App\Filament\Support\OperationBadge;
use App\Models\Embarque;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmbarqueInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Reserva')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('booking')
                            ->label('Reserva'),
                        TextEntry::make('vendedor.nome')
                            ->label('Vendedor')
                            ->placeholder('Não informado'),
                        OperationBadge::entry()
                            ->label('Tipo de reserva'),
                        TextEntry::make('tipo_embarque')
                            ->label('Modal')
                            ->formatStateUsing(fn (?string $state) => Embarque::TYPES[$state] ?? $state),
                        TextEntry::make('cliente.nome')
                            ->label('Cliente')
                            ->placeholder('Não informado'),
                        TextEntry::make('data_topo')
                            ->label('Data de solicitação')
                            ->date('d/m/Y')
                            ->placeholder('Não informada'),
                        TextEntry::make('agente.nome')
                            ->label('Agente')
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
                            ->label('Situação')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => Embarque::STATUSES[$state] ?? $state),
                        TextEntry::make('ctr_desp')
                            ->label('Despesas pendentes de cobrança')
                            ->formatStateUsing(fn ($state) => (int) $state === 1 ? 'Sim' : 'Não'),
                    ]),
                Section::make('Dados do cliente')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('cliente.nome')
                            ->label('Nome')
                            ->placeholder('Não informado'),
                        self::contacts('clientContacts'),
                    ]),
                self::party('Dados do importador', 'importador', 'importerContacts'),
                self::party('Dados do exportador', 'exportador', 'shipperContacts'),
            ]);
    }

    private static function party(string $title, string $relation, string $contacts): Section
    {
        return Section::make($title)
            ->columns(1)
            ->schema([
                TextEntry::make("{$relation}.nome")
                    ->label('Nome')
                    ->placeholder('Não informado'),
                self::contacts($contacts),
            ]);
    }

    /** "Nome (e-mail)" por contato da reserva, um por linha. */
    private static function contacts(string $relation): TextEntry
    {
        return TextEntry::make($relation)
            ->label('Contatos:')
            ->state(fn (Embarque $record) => $record->{$relation}->map(fn ($u) => trim("{$u->nome} ({$u->email})"))->all())
            ->listWithLineBreaks()
            ->placeholder('Não informado');
    }
}

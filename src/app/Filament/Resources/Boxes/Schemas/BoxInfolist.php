<?php

namespace App\Filament\Resources\Boxes\Schemas;

use App\Models\ControleCaixa;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** showSuccess do legado: número, tipo, nome, quantidade de processos e status da caixa. */
class BoxInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Caixa')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('controle_caixa_id')
                            ->label('Número'),
                        TextEntry::make('tipo_caixa')
                            ->label('Tipo')
                            ->formatStateUsing(fn (?string $state) => ControleCaixa::TYPES[$state] ?? $state),
                        TextEntry::make('nome_caixa')
                            ->label('Nome')
                            ->placeholder('Não determinado.'),
                        TextEntry::make('bookings_count')
                            ->label('N° Processos')
                            ->state(fn (ControleCaixa $record) => $record->bookings()->count()),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state) => ControleCaixa::STATUSES[$state] ?? $state)
                            ->color(fn (?string $state) => match ($state) {
                                ControleCaixa::OPEN => 'success',
                                ControleCaixa::CLOSED => 'danger',
                                default => 'gray',
                            }),
                    ]),
            ]);
    }
}

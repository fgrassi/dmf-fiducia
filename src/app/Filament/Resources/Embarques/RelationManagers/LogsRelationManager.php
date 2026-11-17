<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Rastreabilidade da reserva (booking/rastreabilidade e showRastreabilidade do legado): o log do que foi feito, por quem e quando. */
class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Logs';

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('author.nome')
                    ->label('Autor'),
                TextEntry::make('assunto')
                    ->label('Evento'),
                TextEntry::make('justificativa')
                    ->label('Justificativa')
                    ->placeholder('-'),
                TextEntry::make('data')
                    ->label('Data')
                    ->state(fn ($record) => $record->created_at?->format('d/m/Y').' '.$record->created_time),
                TextEntry::make('historico')
                    ->label('Histórico')
                    ->state(fn ($record) => html_entity_decode((string) $record->historico))
                    ->html()
                    ->placeholder('-')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('author'))
            ->emptyStateHeading('Nenhum registro')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data')
                    ->formatStateUsing(fn ($state, $record) => $state?->format('d/m/Y').' '.$record->created_time),
                TextColumn::make('author.nome')
                    ->label('Autor'),
                TextColumn::make('assunto')
                    ->label('Evento')
                    ->wrap(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

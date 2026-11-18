<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Aba Correio da reserva (booking/controleCorreio do legado): só a lista dos envios por Sedex que levam documentos da reserva. */
class MailingsRelationManager extends RelationManager
{
    protected static string $relationship = 'mailings';

    protected static ?string $title = 'Correio';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ! in_array($ownerRecord->finalizado, ['c', 'p'], true);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('estado'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum controle cadastrado')
            ->columns([
                TextColumn::make('num_sedex')
                    ->label('Número Sedex')
                    ->placeholder('-'),
                TextColumn::make('data_envio')
                    ->label('Data de envio')
                    ->date('d/m/Y'),
                TextColumn::make('destinatario')
                    ->label('Destinatário'),
                TextColumn::make('estado.nome')
                    ->label('Estado'),
            ]);
    }
}

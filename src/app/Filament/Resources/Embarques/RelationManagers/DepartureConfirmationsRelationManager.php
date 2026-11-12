<?php

namespace App\Filament\Resources\Embarques\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Subaba "Embarque" do grupo Confirmação: quando e quem confirmou o embarque da reserva. */
class DepartureConfirmationsRelationManager extends RelationManager
{
    protected static string $relationship = 'departureConfirmations';

    protected static ?string $title = 'Confirmação de embarque';

    protected static ?string $emptyLabel = 'Embarque ainda não confirmado';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy'))
            ->emptyStateHeading(static::$emptyLabel)
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Confirmado em')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('createdBy.nome')
                    ->label('Confirmado por')
                    ->placeholder('Não informado'),
            ]);
    }
}

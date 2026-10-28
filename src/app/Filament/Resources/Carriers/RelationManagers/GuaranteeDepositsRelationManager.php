<?php

namespace App\Filament\Resources\Carriers\RelationManagers;

use App\Models\CmnCurrency;
use App\Models\DepositoGarantia;
use App\Models\TipoContainer;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** depositoGarantia, addDepositoGarantia e removeDepositoGarantia (legado): depósito de garantia por equipamento. */
class GuaranteeDepositsRelationManager extends RelationManager
{
    protected static string $relationship = 'guaranteeDeposits';

    protected static ?string $title = 'Depósito de Garantia';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_equipment')
                    ->label('Equipamento')
                    ->options(fn () => TipoContainer::orderBy('nome')->pluck('nome', 'tipo_container_id'))
                    ->searchable()
                    ->required(),
                TextInput::make('valor')
                    ->label('Valor')
                    ->numeric()
                    ->required(),
                Select::make('ref_currency')
                    ->label('Moeda')
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id'))
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['equipment', 'currency']))
            ->emptyStateHeading('Nenhum registro')
            ->columns([
                TextColumn::make('equipment.nome')
                    ->label('Equipamento'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state, DepositoGarantia $record) => $record->currency?->symbol.' '.number_format((float) $state, 2, ',', '.')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar depósito'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?'),
            ]);
    }
}

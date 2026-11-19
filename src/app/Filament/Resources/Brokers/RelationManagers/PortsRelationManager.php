<?php

namespace App\Filament\Resources\Brokers\RelationManagers;

use App\Filament\Resources\BrokerExpenses\BrokerExpenseResource;
use App\Filament\Support\ReportFields;
use App\Models\DespachantePorto;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/** despachante/incluiPorto e removePorto (legado): portos em que o despachante atua, e o caminho para a matriz de despesas de cada um. */
class PortsRelationManager extends RelationManager
{
    protected static string $relationship = 'ports';

    protected static ?string $title = 'Portos em que atua';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ReportFields::port('id_porto', 'Porto')
                    ->required()
                    ->unique(
                        table: 'despachante_porto',
                        column: 'id_porto',
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('id_despachante', $this->getOwnerRecord()->getKey()),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('porto.city'))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('porto.nome')
                    ->label('Porto'),
                TextColumn::make('porto.city.nome')
                    ->label('Cidade'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Incluir Novo Porto'),
            ])
            ->recordActions([
                Action::make('matrix')
                    ->label('Despesas da matriz')
                    ->icon('heroicon-o-table-cells')
                    ->url(fn (DespachantePorto $record) => BrokerExpenseResource::getUrl('index', ['brokerPort' => $record->getKey()])),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->hidden(fn (DespachantePorto $record) => $record->matrix()->exists()),
            ]);
    }
}

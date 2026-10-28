<?php

namespace App\Filament\Resources\Carriers\RelationManagers;

use App\Filament\Resources\ExpenseMatrix\ExpenseMatrixResource;
use App\Models\CmnArmadorPorto;
use App\Models\Porto;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/** addPorto e removePorto (legado): portos em que o armador atua, e o caminho para a matriz de despesas de cada um. */
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
                Select::make('ref_porto')
                    ->label('Porto')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Porto::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'porto_id'))
                    ->getOptionLabelUsing(fn ($value) => Porto::query()->whereKey($value)->value('nome'))
                    ->unique(
                        table: 'cmn_armador_porto',
                        column: 'ref_porto',
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('ref_armador', $this->getOwnerRecord()->getKey()),
                    ),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->select('cmn_armador_porto.*')
                ->join('porto', 'porto.porto_id', '=', 'cmn_armador_porto.ref_porto')
                ->with('porto')
                ->orderBy('porto.nome'))
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('porto.nome')
                    ->label('Porto'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar porto'),
            ])
            ->recordActions([
                Action::make('matrix')
                    ->label('Despesas da matriz')
                    ->icon('heroicon-o-table-cells')
                    ->url(fn (CmnArmadorPorto $record) => ExpenseMatrixResource::getUrl('index', ['armadorPorto' => $record->getKey()])),
                DeleteAction::make()
                    ->hidden(fn (CmnArmadorPorto $record) => $record->matrix()->exists()),
            ]);
    }
}

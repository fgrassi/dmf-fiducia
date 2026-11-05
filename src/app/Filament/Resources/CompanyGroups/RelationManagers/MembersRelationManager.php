<?php

namespace App\Filament\Resources\CompanyGroups\RelationManagers;

use App\Models\Pessoa;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Membros do grupo (showSuccess do legado): adicionar empresa e remover. */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'membros';

    protected static ?string $title = 'Membros';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('pessoa')->join('pessoa', 'pessoa.pessoa_id', '=', 'grupo_empresa_membro.pessoa_id')->select('grupo_empresa_membro.*')->orderBy('pessoa.nome'))
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro adicionado')
            ->columns([
                TextColumn::make('pessoa.nome')
                    ->label('Empresa'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Adicionar empresa')
                    ->modalWidth('lg')
                    ->createAnother(false)
                    ->schema([
                        Select::make('pessoa_id')
                            ->label('Empresa')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                                ->where(fn ($query) => $query->where('nome', 'like', "%{$search}%")->orWhere('cnpj', 'like', "%{$search}%"))
                                ->whereNotIn('pessoa_id', $this->getOwnerRecord()->membros()->select('pessoa_id'))
                                ->orderBy('nome')
                                ->limit(30)
                                ->pluck('nome', 'pessoa_id'))
                            ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                    ])
                    ->mutateDataUsing(fn (array $data) => [...$data, 'created_at' => now(), 'created_by' => auth()->id()]),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Tem certeza que deseja remover este registro?'),
            ]);
    }
}

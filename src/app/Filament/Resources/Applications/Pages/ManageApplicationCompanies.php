<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Resources\Applications\ApplicationResource;
use App\Models\Pessoa;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** acessoAplicacao/empresas (legado): empresas vinculadas à aplicação. */
class ManageApplicationCompanies extends ManageRelatedRecords
{
    protected static string $resource = ApplicationResource::class;

    protected static string $relationship = 'companyLinks';

    protected static ?string $title = 'Empresas';

    protected static ?string $modelLabel = 'empresa';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('pessoa_id')
                    ->label('Empresa')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                        if ($this->getOwnerRecord()->companyLinks()->where('pessoa_id', $value)->exists()) {
                            $fail('Esta empresa já está vinculada a esta aplicação.');
                        }
                    }),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('company'))
            ->columns([
                TextColumn::make('company.nome')
                    ->label('Empresa')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('company.nome')
            ->headerActions([
                CreateAction::make()
                    ->label('Vincular empresa')
                    ->successNotificationTitle('Empresa vinculada com sucesso'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Excluir vínculo'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

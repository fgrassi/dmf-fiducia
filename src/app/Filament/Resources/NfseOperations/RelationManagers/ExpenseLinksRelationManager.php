<?php

namespace App\Filament\Resources\NfseOperations\RelationManagers;

use App\Models\ConfigNfseOperacao;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** configuracaoNfse/add|remove|loadDespesas (legado): despesas desta operação geram recibo e NFS-e separados na baixa. */
class ExpenseLinksRelationManager extends RelationManager
{
    protected static string $relationship = 'expenseLinks';

    protected static ?string $title = 'Despesas vinculadas';

    protected static ?string $modelLabel = 'despesa';

    /** FinExpenses::__toString: nome + " / sigla da unidade". */
    private const LABEL = "CONCAT(e.name, IF(COALESCE(u.acronym, '') = '', '', CONCAT(' / ', u.acronym)))";

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fin_expenses_id')
                    ->label('Despesa')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => DB::table('fin_expenses as e')
                        ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
                        ->where('e.name', 'like', "%{$search}%")
                        ->orderBy('e.name')
                        ->limit(30)
                        ->selectRaw(self::LABEL.' as label, e.id')
                        ->pluck('label', 'id'))
                    ->getOptionLabelUsing(fn ($value) => DB::table('fin_expenses as e')
                        ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
                        ->where('e.id', $value)
                        ->selectRaw(self::LABEL.' as label')
                        ->value('label'))
                    ->rules([
                        fn () => function (string $attribute, mixed $value, Closure $fail) {
                            /** @var ConfigNfseOperacao $operation */
                            $operation = $this->getOwnerRecord();

                            if ($error = $operation->expenseLinkError((int) $value)) {
                                $fail($error);
                            }
                        },
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Ex.: agenciamento 100601. Uma despesa só pode estar em uma operação ativa do mesmo modal.')
            ->modifyQueryUsing(fn ($query) => $query
                ->leftJoin('fin_expenses as e', 'e.id', '=', 'config_nfse_operacao_despesa.fin_expenses_id')
                ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
                ->select('config_nfse_operacao_despesa.*', DB::raw(self::LABEL.' as expense_label')))
            ->emptyStateHeading('Nenhuma despesa vinculada')
            ->columns([
                TextColumn::make('expense_label')
                    ->label('Despesa'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Vincular despesa')
                    ->modalHeading('Vincular despesa')
                    ->createAnother(false),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Remover')
                    ->modalHeading('Remover despesa da operação'),
            ]);
    }
}

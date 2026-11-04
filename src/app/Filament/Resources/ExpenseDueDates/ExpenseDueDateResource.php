<?php

namespace App\Filament\Resources\ExpenseDueDates;

use App\Filament\Clusters\ExpenseSettings;
use App\Filament\Resources\ExpenseDueDates\Pages\ManageExpenseDueDates;
use App\Models\DespesaVencimento;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * despesa/vencimentoDespesa (legado): vencimentos fixos mensais por fornecedor e categoria. O dia 29 a 31 vence no último
 * dia dos meses mais curtos; o aviso de 3 dias antes é o `cobranca:aviso-vencimento-despesa` (ExpenseDueNotice).
 */
class ExpenseDueDateResource extends Resource
{
    protected static ?string $model = DespesaVencimento::class;

    protected static ?string $modelLabel = 'Vencimento';

    protected static ?string $pluralModelLabel = 'Vencimentos';

    protected static ?string $navigationLabel = 'Vencimentos';

    protected static ?string $slug = 'vencimentos';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $cluster = ExpenseSettings::class;

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageCompanyExpenses();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('fornecedor_id')
                    ->label('Fornecedor')
                    ->relationship('supplier', 'nome')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([TextInput::make('nome')->label('Nome')->required()->maxLength(255)]),
                Select::make('categoria_despesa_id')
                    ->label('Categoria')
                    ->relationship('category', 'nome')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([TextInput::make('nome')->label('Nome')->required()->maxLength(255)]),
                TextInput::make('dia_vencimento')
                    ->label('Dia do vencimento')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(31)
                    ->required(),
                Textarea::make('descricao')
                    ->label('Descrição')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['supplier', 'category'])
                ->join('fornecedor', 'fornecedor.fornecedor_id', '=', 'despesa_vencimento.fornecedor_id')
                ->join('categoria_despesa', 'categoria_despesa.categoria_despesa_id', '=', 'despesa_vencimento.categoria_despesa_id')
                ->select('despesa_vencimento.*')
                ->orderBy('despesa_vencimento.dia_vencimento')
                ->orderBy('fornecedor.nome')
                ->orderBy('categoria_despesa.nome'))
            ->columns([
                TextColumn::make('supplier.nome')
                    ->label('Fornecedor'),
                TextColumn::make('category.nome')
                    ->label('Categoria'),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->wrap(),
                TextColumn::make('dia_vencimento')
                    ->label('Dia'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseDueDates::route('/'),
        ];
    }
}

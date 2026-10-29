<?php

namespace App\Filament\Resources\ExpenseRules;

use App\Actions\ExpenseRules\DeleteExpenseRule;
use App\Filament\Resources\ExpenseRules\Pages\ManageExpenseRules;
use App\Models\RegraDespesaCobranca;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** regraCobranca (legado): grupos de despesas por tipo de embarque e Impo/Expo, usados na geração da IC e no split de NFS-e. */
class ExpenseRuleResource extends Resource
{
    protected static ?string $model = RegraDespesaCobranca::class;

    protected static ?string $modelLabel = 'Regra de Despesas';

    protected static ?string $pluralModelLabel = 'Regras de Despesas';

    protected static ?string $navigationLabel = 'Regras de Despesas';

    protected static ?string $slug = 'configuracoes/regras-de-despesas';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 30;

    /** O legado não tinha security.yml (qualquer usuário logado); aqui exige financial, como os demais cadastros da cobrança. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('tipo_embarque')
                    ->label('Tipo de embarque')
                    ->required()
                    ->options(RegraDespesaCobranca::TIPOS_EMBARQUE),
                Select::make('impor_expor')
                    ->label('Impo / Expo')
                    ->required()
                    ->options(RegraDespesaCobranca::IMPOR_EXPOR),
                Toggle::make('obrigatoria')
                    ->label('Despesas obrigatórias na geração de IC')
                    ->helperText('Se marcado, a geração de IC exige ao menos uma despesa deste grupo no embarque (e envia e-mail se faltar). Desmarque para usar o grupo só em outras regras (ex.: split de NFS-e).')
                    ->default(true)
                    ->columnSpanFull(),
                Select::make('expenses')
                    ->label('Despesas vinculadas')
                    ->relationship('expenses', 'name')
                    ->multiple()
                    ->searchable()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->label)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('expenses.unity'))
            ->defaultSort('regra_despesa_cobranca_id', 'desc')
            ->columns([
                TextColumn::make('regra_despesa_cobranca_id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo_embarque')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => RegraDespesaCobranca::TIPOS_EMBARQUE[$state] ?? $state),
                TextColumn::make('impor_expor')
                    ->label('Impo / Expo')
                    ->formatStateUsing(fn ($state) => RegraDespesaCobranca::IMPOR_EXPOR[$state] ?? $state),
                IconColumn::make('obrigatoria')
                    ->label('Obrigatória')
                    ->boolean(),
                TextColumn::make('expenses')
                    ->label('Despesas')
                    ->badge()
                    ->getStateUsing(fn (RegraDespesaCobranca $record) => $record->expenses->map->label->all())
                    ->placeholder('Nenhuma'),
            ])
            ->filters([
                SelectFilter::make('tipo_embarque')
                    ->label('Tipo')
                    ->options(RegraDespesaCobranca::TIPOS_EMBARQUE),
                SelectFilter::make('impor_expor')
                    ->label('Impo / Expo')
                    ->options(RegraDespesaCobranca::IMPOR_EXPOR),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Excluir o grupo também remove as despesas vinculadas e os vínculos com regras de NFS-e dos clientes.')
                    ->using(fn (RegraDespesaCobranca $record) => app(DeleteExpenseRule::class)($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageExpenseRules::route('/'),
        ];
    }
}

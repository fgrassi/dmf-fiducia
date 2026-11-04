<?php

namespace App\Filament\Resources\FiduciaDescontos;

use App\Filament\Resources\FiduciaDescontos\Pages\ManageFiduciaDescontos;
use App\Models\CmnCurrency;
use App\Models\FiduciaDesconto;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** fiduciaDesconto (legado): desconto por cliente Fiducia, lido pelo relatório de embarques dos clientes Fiducia. */
class FiduciaDescontoResource extends Resource
{
    protected static ?string $model = FiduciaDesconto::class;

    protected static ?string $modelLabel = 'Desconto Cliente Fiducia';

    protected static ?string $pluralModelLabel = 'Cadastro de Desconto Clientes Fiducia';

    protected static ?string $navigationLabel = 'Desconto Clientes Fiducia';

    protected static ?string $slug = 'financeiro/desconto-clientes-fiducia';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $navigationParentItem = 'Cadastros';

    protected static ?int $navigationSort = 20;

    /** Menu 9002 do legado: aparece para quem acessa financeiro/index (credencial financial). */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('pessoa_id')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome'))
                    ->columnSpanFull(),
                Select::make('tipo_desconto')
                    ->label('Tipo de Desconto')
                    ->required()
                    ->options(FiduciaDesconto::TIPOS),
                Select::make('moeda_id')
                    ->label('Moeda')
                    ->required()
                    ->options(fn () => CmnCurrency::orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('valor')
                    ->label('Valor')
                    ->required()
                    ->numeric()
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->has('pessoa')->with(['pessoa', 'moeda']))
            ->defaultSort('pessoa.nome')
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('pessoa.nome')
                    ->label('Cliente')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tipo_desconto')
                    ->label('Tipo de Desconto')
                    ->formatStateUsing(fn (?string $state) => FiduciaDesconto::TIPOS[$state] ?? $state),
                TextColumn::make('moeda.name')
                    ->label('Moeda'),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->numeric(2, ',', '.'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading('Tem certeza que deseja excluir este registro?')
                    ->modalDescription('Esta operação não pode ser desfeita'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFiduciaDescontos::route('/'),
        ];
    }
}

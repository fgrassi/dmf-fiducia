<?php

namespace App\Filament\Resources\ClientBoletos;

use App\Actions\ClientBoletos\DeleteClientBoleto;
use App\Filament\Resources\ClientBoletos\Pages\ManageClientBoletos;
use App\Models\FinClientsBoleto;
use App\Models\Pessoa;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** clientBoleto (legado): prazos negociados e boleto por cliente, separados em IMPO e EXPO. */
class ClientBoletoResource extends Resource
{
    public const YES_NO = [0 => 'NÃO', 1 => 'SIM'];

    protected static ?string $model = FinClientsBoleto::class;

    protected static ?string $modelLabel = 'Prazo Negociado';

    protected static ?string $pluralModelLabel = 'Prazos Negociados';

    protected static ?string $navigationLabel = 'Clientes com Boleto';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 21;

    /** security.yml do módulo (legado): credencial financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('ref_client')
                    ->label('Cliente')
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Pessoa::query()
                        ->where('nome', 'like', "%{$search}%")
                        ->orWhere('cnpj', 'like', "%{$search}%")
                        ->orderBy('nome')
                        ->limit(30)
                        ->pluck('nome', 'pessoa_id'))
                    ->getOptionLabelUsing(fn ($value) => Pessoa::query()->whereKey($value)->value('nome')),
                TextInput::make('prazo')
                    ->label('Observações'),
                TextInput::make('prazo_imp')
                    ->label('Prazo Negociado IMPO')
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('prazo_exp')
                    ->label('Prazo Negociado EXPO')
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Select::make('boleto_imp')
                    ->label('Boleto IMPO')
                    ->options(self::YES_NO)
                    ->default(0)
                    ->selectablePlaceholder(false),
                Select::make('boleto_exp')
                    ->label('Boleto EXPO')
                    ->options(self::YES_NO)
                    ->default(0)
                    ->selectablePlaceholder(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('client'))
            ->defaultSort('client.nome')
            ->paginated(false)
            ->emptyStateHeading('Nenhum registro encontrado')
            ->columns([
                TextColumn::make('client.nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('client.cnpj')
                    ->label('CNPJ')
                    ->searchable(),
                TextColumn::make('prazo')
                    ->label('OBS')
                    ->wrap(),
                ColumnGroup::make('IMPO', [
                    TextColumn::make('prazo_imp')
                        ->label('Prazo')
                        ->formatStateUsing(fn ($state) => $state ?: '-')
                        ->sortable(),
                    TextColumn::make('boleto_imp')
                        ->label('Boleto')
                        ->formatStateUsing(fn ($state) => self::YES_NO[(int) $state])
                        ->sortable(),
                ]),
                ColumnGroup::make('EXPO', [
                    TextColumn::make('prazo_exp')
                        ->label('Prazo')
                        ->formatStateUsing(fn ($state) => $state ?: '-')
                        ->sortable(),
                    TextColumn::make('boleto_exp')
                        ->label('Boleto')
                        ->formatStateUsing(fn ($state) => self::YES_NO[(int) $state])
                        ->sortable(),
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('remover')
                    ->label('Remover')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Remover Negociação')
                    ->modalDescription('Tem certeza que deseja remover este item?')
                    ->schema([
                        Textarea::make('justificativa')
                            ->label('Justificativa')
                            ->required()
                            ->validationMessages(['required' => 'É necessário informar a justificativa!']),
                    ])
                    ->action(function (FinClientsBoleto $record, array $data, Action $action) {
                        app(DeleteClientBoleto::class)($record, $data['justificativa'], auth()->user());
                        $action->success();
                    })
                    ->successNotificationTitle('Negociação removida.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageClientBoletos::route('/'),
        ];
    }
}

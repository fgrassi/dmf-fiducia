<?php

namespace App\Filament\Resources\Carriers;

use App\Actions\Carriers\PortalReferenceMap;
use App\Filament\Resources\Carriers\Pages\ManageCarriers;
use App\Filament\Resources\Carriers\Pages\ViewCarrier;
use App\Filament\Resources\Carriers\RelationManagers\BanksRelationManager;
use App\Filament\Resources\Carriers\RelationManagers\GuaranteeDepositsRelationManager;
use App\Filament\Resources\Carriers\RelationManagers\PortsRelationManager;
use App\Models\Armador;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** armador (legado): cadastro de armadores. */
class CarrierResource extends Resource
{
    protected static ?string $model = Armador::class;

    protected static ?string $modelLabel = 'Armador';

    protected static ?string $pluralModelLabel = 'Armadores';

    protected static ?string $navigationLabel = 'Armadores';

    protected static ?string $slug = 'cadastros/armadores';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?int $navigationSort = 10;

    /** security.yml do módulo (legado): credencial auxiliares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('auxiliares');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nome')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('cnpj')
                    ->label('CNPJ')
                    ->maxLength(255),
                TextInput::make('scac')
                    ->label('SCAC')
                    ->maxLength(255),
                TextInput::make('free_time_padrao')
                    ->label('Free Time Padrão (Detention)')
                    ->numeric(),
                Select::make('recalcular')
                    ->label('Recalcular Cobranças')
                    ->options([1 => 'SIM', 0 => 'NÃO'])
                    ->default(1)
                    ->required()
                    ->helperText('SIM: a cobrança pode ser recalculada; clientes na lista de exceção (taxa negociada) recebem a IC só no financeiro até o recálculo, e a NFSe só é emitida após ele. NÃO: não há recálculo; a NFSe é emitida junto com a instrução de cobrança e a IC/NFSe vão direto ao cliente, mesmo que ele esteja na lista de exceção.'),
                Select::make('integracao_portal')
                    ->label('Disponível para Integração Portal')
                    ->options([1 => 'SIM', 0 => 'NÃO'])
                    ->default(0)
                    ->required(),
                Section::make('Consulta ao Portal Integrador (de-para)')
                    ->description('Defina, para cada parâmetro do portal, qual campo da reserva será enviado na consulta. Exemplo HMM: Booking armador ← bl.mbl e Container ← bl.equipamento.nr_container.')
                    ->columnSpanFull()
                    ->schema(collect(['impo' => 'Importação', 'expo' => 'Exportação'])
                        ->map(fn (string $label, string $operation) => Fieldset::make($label)
                            ->columns(3)
                            ->schema(collect(PortalReferenceMap::parameters())
                                ->map(fn (string $parameter, string $key) => Select::make("portal_refs.{$operation}.{$key}")
                                    ->label($parameter)
                                    ->options(PortalReferenceMap::sources())
                                    ->placeholder('— não utilizar —'))
                                ->values()
                                ->all()))
                        ->values()
                        ->all()),
                RichEditor::make('obs')
                    ->label('Obs')
                    ->columnSpanFull(),
                RichEditor::make('obs_free_time')
                    ->label('Obs Free Time')
                    ->columnSpanFull(),
                RichEditor::make('instrucao_imo')
                    ->label('Instrução IMO')
                    ->columnSpanFull(),
                RichEditor::make('late_payment')
                    ->label('Late Payment')
                    ->columnSpanFull(),
            ]);
    }

    /** show do legado: cadastro e embarques realizados (não cancelados); os portos são a aba Portos em que atua. */
    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes do Armador')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('nome')
                            ->label('Nome'),
                        TextEntry::make('scac')
                            ->label('SCAC')
                            ->placeholder('Não Informado'),
                        TextEntry::make('free_time_padrao')
                            ->label('Free Time Detention Padrão'),
                        IconEntry::make('recalcular')
                            ->label('Recalcular Cobranças')
                            ->boolean(),
                        IconEntry::make('invoice_impo')
                            ->label('Exige Invoice (Importação)')
                            ->boolean(),
                        IconEntry::make('invoice_expo')
                            ->label('Exige Invoice (Exportação)')
                            ->boolean(),
                        IconEntry::make('integracao_portal')
                            ->label('Disponível para Integração Portal')
                            ->boolean(),
                        TextEntry::make('embarques_realizados')
                            ->label('Quantidade Embarques Realizados')
                            ->state(fn (Armador $record) => $record->embarques()->where('finalizado', '<>', 'c')->count()),
                    ]),
                Section::make('Consulta ao Portal Integrador (de-para)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('portal_refs_impo')
                            ->label('Importação')
                            ->state(fn (Armador $record) => PortalReferenceMap::describe(PortalReferenceMap::decode($record->integracao_portal_refs_impo))),
                        TextEntry::make('portal_refs_expo')
                            ->label('Exportação')
                            ->state(fn (Armador $record) => PortalReferenceMap::describe(PortalReferenceMap::decode($record->integracao_portal_refs_expo))),
                    ]),
                Section::make('Observações')
                    ->columns(2)
                    ->schema([
                        Section::make('Observações')
                            ->compact()
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('obs')
                                    ->hiddenLabel()
                                    ->html()
                                    ->placeholder('Não Informado'),
                            ]),
                        Section::make('Observações Free Time')
                            ->compact()
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('obs_free_time')
                                    ->hiddenLabel()
                                    ->html()
                                    ->placeholder('Não Informado'),
                            ]),
                        Section::make('Instrução para IMO')
                            ->compact()
                            ->schema([
                                TextEntry::make('instrucao_imo')
                                    ->hiddenLabel()
                                    ->html()
                                    ->placeholder('Não Informado'),
                            ]),
                        Section::make('Late Payment')
                            ->compact()
                            ->schema([
                                TextEntry::make('late_payment')
                                    ->hiddenLabel()
                                    ->html()
                                    ->placeholder('Não Informado'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('nome')
            ->columns([
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('scac')
                    ->label('SCAC')
                    ->sortable(),
                IconColumn::make('integracao_portal')
                    ->label('Portal')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->modalDescription('Confirmar exclusão?')
                    ->hidden(fn (Armador $record) => $record->embarques()->exists() || $record->invoiceControls()->exists()),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PortsRelationManager::class,
            BanksRelationManager::class,
            GuaranteeDepositsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCarriers::route('/'),
            'view' => ViewCarrier::route('/{record}'),
        ];
    }
}

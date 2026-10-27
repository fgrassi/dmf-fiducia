<?php

namespace App\Filament\Resources\NfseOperations;

use App\Filament\Resources\NfseOperations\Pages\CreateNfseOperation;
use App\Filament\Resources\NfseOperations\Pages\EditNfseOperation;
use App\Filament\Resources\NfseOperations\Pages\ListNfseOperations;
use App\Filament\Resources\NfseOperations\RelationManagers\ExpenseLinksRelationManager;
use App\Models\ConfigNfseOperacao;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * configuracaoNfse/*Operacao (legado): códigos fiscais e descrição do serviço por tipo de operação.
 * Fora do menu: a listagem fica dentro da página Configuração NFS-e (NfseSettings).
 */
class NfseOperationResource extends Resource
{
    public const TRIB_ISSQN = ['1' => '1 — Tributável', '2' => '2 — Imunidade', '3' => '3 — Exportação de serviço', '4' => '4 — Não incidência'];

    public const TIPO_RETENCAO = [
        '0' => '0 — Sem retenção',
        '3' => '3 — PIS/COFINS/CSLL retidos',
        '4' => '4 — PIS/COFINS retidos',
        '5' => '5 — Só PIS retido',
        '6' => '6 — Só COFINS retido',
        '7' => '7 — COFINS/CSLL retidos',
        '8' => '8 — Só CSLL retido',
        '9' => '9 — PIS/CSLL retidos',
        '1' => '1 — (transitório) PIS/COFINS retidos',
        '2' => '2 — (transitório) não retidos',
    ];

    /** CST PIS/COFINS — domínio NT 007 (saídas / emissão de NFS-e). */
    public const PIS_COFINS_CST = [
        '00' => '00 — Nenhum',
        '01' => '01 — Operação Tributável com Alíquota Básica',
        '02' => '02 — Operação Tributável com Alíquota Diferenciada',
        '03' => '03 — Operação Tributável com Alíquota por Unidade de Medida',
        '04' => '04 — Operação Tributável monofásica — Revenda a Alíquota Zero',
        '05' => '05 — Operação Tributável por Substituição Tributária',
        '06' => '06 — Operação Tributável a Alíquota Zero',
        '07' => '07 — Operação Isenta da Contribuição',
        '08' => '08 — Operação sem Incidência da Contribuição',
        '09' => '09 — Operação com Suspensão da Contribuição',
        '49' => '49 — Outras Operações de Saída',
    ];

    protected static ?string $model = ConfigNfseOperacao::class;

    protected static ?string $modelLabel = 'Operação NFS-e';

    protected static ?string $pluralModelLabel = 'Operações NFS-e';

    protected static ?string $slug = 'configuracoes/nfse-operacoes';

    protected static ?string $recordTitleAttribute = 'codigo_operacao';

    protected static bool $shouldRegisterNavigation = false;

    /** security.yml do módulo (legado): credenciais admin ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public static function form(Schema $schema): Schema
    {
        $percent = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)
            ->numeric()
            ->step('any')
            ->suffix('%');

        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])
                    ->columnSpanFull()
                    ->schema([
                        Section::make('Operação')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->columns(2)
                            ->schema([
                                TextInput::make('codigo_operacao')
                                    ->label('Código da operação')
                                    ->helperText('Ex.: EXPO, IMPO (único).')
                                    ->required()
                                    ->maxLength(20)
                                    ->unique(ignoreRecord: true)
                                    ->extraInputAttributes(['class' => 'uppercase'])
                                    ->dehydrateStateUsing(fn (string $state) => strtoupper(trim($state))),
                                TextInput::make('nome')
                                    ->label('Nome')
                                    ->maxLength(100),
                                Select::make('impor_expor')
                                    ->label('Impo/Expo (embarque)')
                                    ->helperText('Usado para pré-selecionar a operação conforme o embarque da cobrança.')
                                    ->options(ConfigNfseOperacao::IMPOR_EXPOR),
                                TextInput::make('ordem')
                                    ->label('Ordem')
                                    ->integer()
                                    ->default(0),
                                Toggle::make('ativo')
                                    ->label('Ativo')
                                    ->default(true)
                                    ->columnSpanFull(),
                                Textarea::make('descricao_servico')
                                    ->label('Descrição do serviço')
                                    ->rows(6)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('Serviço e ISS')
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->columns(2)
                            ->schema([
                                TextInput::make('codigo_tributacao_nacional')
                                    ->label('Código do serviço (cTribNac)')
                                    ->helperText('Ex.: 990101 (não incidência) ou 100601 (agenciamento).')
                                    ->maxLength(10),
                                TextInput::make('codigo_nbs')
                                    ->label('Código NBS (cNBS)')
                                    ->helperText('Ex.: 1.0502.14.90 ou 106070000. Obrigatório se IBS/CBS estiver habilitado.')
                                    ->maxLength(20)
                                    // NBS: pontuado flexível (1.0502.14.90 / 1.05.04.40.90) ou 9 dígitos
                                    ->regex('/^(\d+(\.\d+)+|\d{9})?$/'),
                                Select::make('trib_issqn')
                                    ->label('Tributação ISSQN (tribISSQN)')
                                    ->helperText('Para cTribNac 990101 use 4 (não incidência). Não envia alíquota/valor ISS.')
                                    ->options(self::TRIB_ISSQN)
                                    ->default('1'),
                                $percent('aliquota_iss', 'Alíquota ISS'),
                                Toggle::make('iss_retido')
                                    ->label('Retém ISS')
                                    ->default(false)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('IBS/CBS')
                            ->icon(Heroicon::OutlinedReceiptPercent)
                            ->columns(2)
                            ->schema([
                                Toggle::make('ibs_cbs_habilitado')
                                    ->label('Incluir bloco IBS/CBS')
                                    ->default(false)
                                    ->columnSpanFull(),
                                TextInput::make('ibs_cbs_c_ind_op')
                                    ->label('cIndOp')
                                    ->maxLength(6),
                                TextInput::make('ibs_cbs_c_class_trib')
                                    ->label('cClassTrib')
                                    ->helperText('6 dígitos; os 3 primeiros = CST IBS.')
                                    ->maxLength(6),
                                TextInput::make('ibs_cbs_cst')
                                    ->label('CST IBS')
                                    ->helperText('3 dígitos; deve ser o prefixo de cClassTrib.')
                                    ->maxLength(3),
                                TextInput::make('ibs_cbs_cst_cbs')
                                    ->label('CST CBS')
                                    ->maxLength(3),
                            ]),
                        Section::make('PIS / COFINS / totTrib')
                            ->icon(Heroicon::OutlinedCalculator)
                            ->columns(2)
                            ->schema([
                                Toggle::make('pis_cofins_habilitado')
                                    ->label('Incluir PIS/COFINS')
                                    ->default(false)
                                    ->columnSpanFull(),
                                Select::make('pis_cofins_cst')
                                    ->label('CST PIS/COFINS')
                                    ->helperText('NT 007. Ex.: 01 (alíquota básica). CST 08/09 enviam só o código no XML.')
                                    ->options(self::PIS_COFINS_CST)
                                    ->placeholder('— Selecione —'),
                                Select::make('pis_cofins_tipo_retencao')
                                    ->label('Tipo retenção PIS/COFINS')
                                    ->options(self::TIPO_RETENCAO)
                                    ->default('0'),
                                $percent('aliquota_pis', 'Alíquota PIS')
                                    ->helperText('Ex.: 1,65 (CST 01).'),
                                $percent('aliquota_cofins', 'Alíquota COFINS')
                                    ->helperText('Ex.: 7,60 (CST 01).'),
                                $percent('tot_trib_percentual_federal', '% tributos federal (totTrib)')
                                    ->helperText('Lei 12.741 — se informado, gera pTotTrib no XML.'),
                                $percent('tot_trib_percentual_estadual', '% tributos estadual (totTrib)'),
                                $percent('tot_trib_percentual_municipal', '% tributos municipal (totTrib)'),
                            ]),
                        Section::make('Observações')
                            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                            ->collapsible()
                            ->columnSpanFull()
                            ->schema([
                                Textarea::make('observacoes')
                                    ->hiddenLabel()
                                    ->rows(4),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('expenseLinks'))
            ->defaultSort(fn ($query) => $query->orderBy('ordem')->orderBy('codigo_operacao'))
            ->columns([
                TextColumn::make('codigo_operacao')
                    ->label('Código')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('nome')
                    ->label('Nome')
                    ->searchable(),
                TextColumn::make('impor_expor')
                    ->label('Impo/Expo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ConfigNfseOperacao::IMPOR_EXPOR[$state] ?? $state)
                    ->color(fn ($state) => $state === 'e' ? 'info' : 'warning'),
                TextColumn::make('codigo_tributacao_nacional')
                    ->label('cTribNac'),
                TextColumn::make('codigo_nbs')
                    ->label('NBS'),
                TextColumn::make('ibs_cbs_c_class_trib')
                    ->label('cClassTrib'),
                IconColumn::make('ibs_cbs_habilitado')
                    ->label('IBS/CBS')
                    ->boolean(),
                TextColumn::make('expense_links_count')
                    ->label('Despesas vinculadas')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('ativo')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Editar')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (ConfigNfseOperacao $record) => self::getUrl('edit', ['record' => $record])),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ExpenseLinksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNfseOperations::route('/'),
            'create' => CreateNfseOperation::route('/create'),
            'edit' => EditNfseOperation::route('/{record}/edit'),
        ];
    }
}

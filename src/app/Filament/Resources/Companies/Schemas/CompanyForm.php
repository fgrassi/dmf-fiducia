<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Actions\Companies\LookupCompanyCnpj;
use App\Filament\Actions\SearchCityAction;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class CompanyForm
{
    private const YES_NO_INT = [1 => 'Sim', 0 => 'Não'];

    private const YES_NO_FLAG = ['s' => 'Sim', 'n' => 'Não'];

    public static function configure(Schema $schema): Schema
    {
        $isFinancial = fn () => (bool) auth()->user()?->hasLegacyCredential('financial');

        return $schema
            ->components([
                Section::make('Dados')
                    ->columns(2)
                    ->schema([
                        TextInput::make('nome')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('razao_social')
                            ->label('Razão Social')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('cnpj')
                            ->label('CNPJ')
                            ->required()
                            ->maxLength(255)
                            ->suffixAction(
                                Action::make('lookupCnpj')
                                    ->label('Consultar CNPJ')
                                    ->icon(Heroicon::MagnifyingGlass)
                                    ->modalHeading('Dados da OpenCNPJ')
                                    ->modalSubmitActionLabel('Adicionar dados')
                                    ->modalSubmitAction(fn (Action $action, Get $get) => app(LookupCompanyCnpj::class)((string) $get('cnpj'))['erro'] ? false : $action)
                                    ->schema(function (Get $get): array {
                                        ['erro' => $error, 'fields' => $fields] = app(LookupCompanyCnpj::class)((string) $get('cnpj'));

                                        if ($error) {
                                            return [Text::make($error)->color('danger')];
                                        }

                                        return [
                                            CheckboxList::make('fields')
                                                ->label('Dados a adicionar')
                                                ->options(array_map(fn (array $field) => $field['label'], $fields))
                                                ->default(array_keys($fields))
                                                ->bulkToggleable()
                                                ->required(),
                                        ];
                                    })
                                    ->action(function (array $data, Get $get, Set $set): void {
                                        $fields = app(LookupCompanyCnpj::class)((string) $get('cnpj'))['fields'];

                                        foreach ($data['fields'] ?? [] as $field) {
                                            foreach ($fields[$field]['values'] as $name => $value) {
                                                $set($name, $value);
                                            }
                                        }
                                    }),
                            ),
                        TextInput::make('ie')
                            ->label('I.E.')
                            ->maxLength(255),
                        TextInput::make('fone1')
                            ->label('Telefone')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->helperText('E-mail informado no cadastro da NFSE')
                            ->maxLength(255),
                        TextInput::make('domain')
                            ->label('Domínio')
                            ->helperText('Parte final dos endereços de email, depois do "@"')
                            ->maxLength(255),
                        TextInput::make('rnc')
                            ->label('RNC')
                            ->maxLength(255),
                        Textarea::make('dados_bancarios')
                            ->label('Dados bancários')
                            ->rows(3)
                            ->columnSpanFull(),
                        CheckboxList::make('tipo_empresa')
                            ->label('Tipos de empresa')
                            ->options(fn () => DB::table('tipo_pessoa')->where('tipo_pessoa_id', '<>', 1)->orderBy('nome')->pluck('nome', 'tipo_pessoa_id'))
                            ->columns(3)
                            ->columnSpanFull(),
                        Select::make('pessoa_sede')
                            ->label('Escritório atual')
                            ->options(fn () => DB::table('sedes')->orderBy('nome')->pluck('nome', 'id')),
                    ]),
                Section::make('Endereço')
                    ->columns(2)
                    ->schema([
                        Select::make('paises_id')
                            ->label('País')
                            ->required()
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('estados_id', null);
                                $set('cidades_id', null);
                            })
                            ->options(fn () => DB::table('paises')->orderBy('nome')->pluck('nome', 'paises_id')),
                        Select::make('estados_id')
                            ->label('Estado')
                            ->required()
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('cidades_id', null))
                            ->options(fn (Get $get) => DB::table('estados')->where('paises_id', $get('paises_id'))->orderBy('nome')->pluck('nome', 'estados_id')),
                        Select::make('cidades_id')
                            ->label('Cidade')
                            ->required()
                            ->searchable()
                            ->options(fn (Get $get) => DB::table('cidades')->where('estados_id', $get('estados_id'))->orderBy('nome')->pluck('nome', 'cidades_id')),
                        TextInput::make('codigo_municipio_ibge')
                            ->label('Código município IBGE')
                            ->required()
                            ->maxLength(7)
                            ->suffixAction(SearchCityAction::make('codigo_municipio_ibge')),
                        TextInput::make('bairro')
                            ->label('Bairro')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('cep')
                            ->label('CEP')
                            ->required()
                            ->maxLength(255),
                        Textarea::make('endereco')
                            ->label('Endereço')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Comercial')
                    ->columns(2)
                    ->schema([
                        TextInput::make('per_desc')
                            ->label('Percentual de desconto')
                            ->numeric(),
                        Select::make('ctt_agente')
                            ->label('Mostrar contato do agente na origem')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('nf_completa')
                            ->label('NF completa')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('isento_iof')
                            ->label('Isento de IOF')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('mostrar_somatorio_iof')
                            ->label('Mostrar somatório IOF')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('seguro_obrigatorio')
                            ->label('Seguro Obrigatório')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('separa_taxas')
                            ->label('Separa taxas')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('verificar_fiducia')
                            ->label('Verificar Fiducia')
                            ->options(['i' => 'INVOICE', 'c' => 'CUSTOMER REFERENCE', 'ic' => 'INVOICE/CUSTOMER REF']),
                        Select::make('tipo_verificacao')
                            ->label('Tipo de verificação')
                            ->options(['c' => 'CLIENTE', 'i' => 'IMPORTADOR', 'e' => 'EXPORTADOR']),
                        Select::make('armadores_permitidos')
                            ->label('Armadores')
                            ->helperText('Os armadores selecionados aqui serão os únicos exibidos na cotação de importação')
                            ->multiple()
                            ->searchable()
                            ->options(fn () => DB::table('armador')->whereNotNull('nome')->where('nome', '<>', '')->orderBy('nome')->pluck('nome', 'armador_id'))
                            ->columnSpanFull(),
                        Textarea::make('obs')
                            ->label('Obs')
                            ->rows(3),
                        Textarea::make('obs_cotacao')
                            ->label('Obs Cotação IMPO')
                            ->rows(3),
                    ]),
                Section::make('Envios e controles')
                    ->columns(2)
                    ->schema([
                        Select::make('histCompleto')
                            ->label('Receber Históricos Completos')
                            ->helperText('As mensagens de tracking são enviadas com todo o histórico')
                            ->options(['true' => 'Sim', 'false' => 'Não'])
                            ->default('false')
                            ->required(),
                        Select::make('histPdf')
                            ->label('Enviar Tracking com PDF')
                            ->helperText('As mensagens de tracking enviam um arquivo em PDF em anexo')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('histtxt')
                            ->label('Enviar Tracking em TXT')
                            ->helperText('As mensagens de tracking são enviadas sem nenhuma formatação (TXT)')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('reportHtml')
                            ->label('Relatório em HTML')
                            ->helperText('Os relatórios são enviados em HTML e não em PDF')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('envio_ind')
                            ->label('Enviar Relatórios Coletivos')
                            ->helperText('Os relatórios são enviados em um único e-mail')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('ctr_liberacao_hbl_origem')
                            ->label('Controlar Envio Liberação HBL Origem')
                            ->helperText('Irá criar controle sobre o envio de HBL pela origem')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('ctr_envio_valores')
                            ->label('Controlar Envio de Valores Finais ao Despachante')
                            ->helperText('Irá criar um controle sobre o envio de valores finais ao despachante')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                        Select::make('ctr_liberacao')
                            ->label('Controlar Liberação dos Embarques no Destino')
                            ->helperText('As cobranças apenas serão geradas quando houver a liberação manual')
                            ->options(self::YES_NO_FLAG)
                            ->default('n')
                            ->required(),
                    ]),
                Section::make('Financeiro')
                    ->columns(2)
                    ->visible($isFinancial)
                    ->schema([
                        Select::make('liberar_sem_bl')
                            ->label('Liberar BL sem pagamento')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Select::make('ctr_detention_demurrage')
                            ->label('Cliente somente aceita pagar taxas do Armador para Detention/Demurrage')
                            ->options(self::YES_NO_INT)
                            ->default(0)
                            ->required(),
                        Textarea::make('observacao_detention_demurrage')
                            ->label('Observações para cobrança de Detention/Demurrage')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

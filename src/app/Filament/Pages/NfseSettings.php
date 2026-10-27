<?php

namespace App\Filament\Pages;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Filament\Resources\NfseOperations\NfseOperationResource;
use App\Models\ConfigNfse;
use App\Models\ConfigNfseOperacao;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** configuracaoNfse/edit (legado): dados globais do emissor e da API de NFS-e (config_nfse id 1) e, abaixo, as operações. */
class NfseSettings extends Page implements HasTable
{
    use InteractsWithTable;

    public const OP_SIMP_NAC = ['1' => '1 — Não optante', '2' => '2 — Optante — MEI', '3' => '3 — Optante — ME/EPP'];

    public const REG_ESP_TRIB = [
        '0' => '0 — Nenhum',
        '1' => '1 — Ato cooperado (cooperativa)',
        '2' => '2 — Estimativa',
        '3' => '3 — Microempresa municipal',
        '4' => '4 — Notário ou registrador',
        '5' => '5 — Profissional autônomo',
        '6' => '6 — Sociedade de profissionais',
    ];

    public const FIN_NFSE = ['0' => '0 — Regular', '1' => '1 — Crédito', '2' => '2 — Débito'];

    public const IND_FINAL = ['0' => '0 — Não', '1' => '1 — Sim (uso/consumo pessoal)'];

    protected static ?string $title = 'Configuração NFS-e';

    protected static ?string $slug = 'configuracoes/nfse';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configurações';

    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    /** security.yml do módulo (legado): credenciais admin ou financial. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasLegacyCredential('financial');
    }

    public function mount(): void
    {
        $config = ConfigNfse::current();

        $this->form->fill([
            ...$config->toArray(),
            'validar_cep' => (string) ($config->validar_cep ?? '1'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Grid::make(['default' => 1, 'lg' => 2])
                    ->schema([
                        Section::make('API e webhook')
                            ->icon(Heroicon::OutlinedServerStack)
                            ->schema([
                                TextInput::make('api_url')
                                    ->label('URL da API')
                                    ->maxLength(255),
                                TextInput::make('api_token')
                                    ->label('Token Bearer')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255),
                                TextInput::make('webhook_url')
                                    ->label('URL do webhook (callback)')
                                    ->helperText('Enviado no POST /nfse. Ex.: http://host/api.php/api/nfse/webhook — o emissor notifica status final (autorizada/rejeitada).')
                                    ->maxLength(2048),
                                TextInput::make('webhook_secret')
                                    ->label('Segredo HMAC do webhook')
                                    ->helperText('Mesmo valor de NFSE_WEBHOOK_SECRET no emissor. Quando preenchido, valida o header X-Nfse-Signature (sha256=HMAC).')
                                    ->password()
                                    ->revealable()
                                    ->maxLength(255),
                            ]),
                        Section::make('Regime tributário')
                            ->icon(Heroicon::OutlinedScale)
                            ->schema([
                                Select::make('op_simp_nac')
                                    ->label('Situação no Simples Nacional (opSimpNac)')
                                    ->helperText('Tag opSimpNac no XML (prest/regTrib). Valor 4 (optante pendente / NT 009) ainda não é aceito pela API.')
                                    ->options(self::OP_SIMP_NAC),
                                Select::make('reg_esp_trib')
                                    ->label('Regime especial de tributação (regEspTrib)')
                                    ->helperText('Tag regEspTrib no XML (prest/regTrib).')
                                    ->options(self::REG_ESP_TRIB),
                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('serie_dps')
                                            ->label('Série DPS')
                                            ->maxLength(5),
                                        Select::make('validar_cep')
                                            ->label('Validar CEP do tomador (BrasilAPI)')
                                            ->options(['1' => 'Sim', '0' => 'Não'])
                                            ->helperText('Quando ativo, o CEP do tomador é consultado em brasilapi.com.br antes da emissão. CEP inválido impede o envio à Receita (a baixa continua).')
                                            ->selectablePlaceholder(false)
                                            ->required(),
                                    ]),
                            ]),
                        Section::make('Emissor')
                            ->icon(Heroicon::OutlinedBuildingOffice2)
                            ->columns(2)
                            ->schema([
                                TextInput::make('emissor_cnpj')
                                    ->label('CNPJ do emissor')
                                    ->maxLength(20),
                                TextInput::make('emissor_inscricao_municipal')
                                    ->label('Inscrição municipal')
                                    ->maxLength(50),
                                TextInput::make('emissor_razao_social')
                                    ->label('Razão social do emissor')
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                                TextInput::make('emissor_email')
                                    ->label('E-mail do emissor')
                                    ->email()
                                    ->maxLength(255),
                                TextInput::make('emissor_telefone')
                                    ->label('Telefone do emissor')
                                    ->tel()
                                    ->maxLength(20),
                            ]),
                        Section::make('Município do emissor')
                            ->description('Busque pelo nome na API do IBGE; código, nome e UF são preenchidos juntos.')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->columns(4)
                            ->schema([
                                TextInput::make('emissor_uf')
                                    ->label('UF do emissor')
                                    ->helperText('Filtra a busca.')
                                    ->maxLength(2)
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn (?string $state) => filled($state) ? strtoupper($state) : null),
                                Select::make('emissor_codigo_municipio')
                                    ->label('Buscar município (IBGE)')
                                    ->placeholder('Nome da cidade (mín. 2 letras)')
                                    ->searchable()
                                    ->searchDebounce(350)
                                    ->getSearchResultsUsing(fn (string $search, Get $get) => collect(app(AddressLookup::class)->searchCities($search, (string) $get('emissor_uf')))
                                        ->mapWithKeys(fn (array $c) => [$c['id'] => "{$c['nome']}".($c['uf'] ? " / {$c['uf']}" : '')." ({$c['id']})"])
                                        ->all())
                                    ->getOptionLabelUsing(fn ($value, Get $get) => collect([$get('emissor_nome_municipio'), $get('emissor_uf')])->filter()->implode(' / ')." ({$value})")
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set) {
                                        $city = $state ? app(AddressLookup::class)->city($state) : null;
                                        $set('emissor_nome_municipio', $city['nome'] ?? null);

                                        if ($city['uf'] ?? null) {
                                            $set('emissor_uf', $city['uf']);
                                        }
                                    })
                                    ->columnSpan(3),
                                TextInput::make('emissor_nome_municipio')
                                    ->label('Nome do município')
                                    ->readOnly()
                                    ->maxLength(255)
                                    ->columnSpanFull(),
                            ]),
                        Section::make('IBS/CBS — padrões globais')
                            ->description('Aplicados quando a operação habilita o bloco IBS/CBS.')
                            ->icon(Heroicon::OutlinedReceiptPercent)
                            ->columns(2)
                            ->columnSpanFull()
                            ->schema([
                                Select::make('ibs_cbs_fin_nfse')
                                    ->label('Finalidade NFS-e (finNFSe) — padrão')
                                    ->options(self::FIN_NFSE),
                                Select::make('ibs_cbs_ind_final')
                                    ->label('Uso/consumo pessoal (indFinal) — padrão')
                                    ->options(self::IND_FINAL),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Salvar')
                                ->submit('save'),
                        ]),
                    ]),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return NfseOperationResource::table($table)
            ->query(ConfigNfseOperacao::query())
            ->heading('Operações / tipos de serviço')
            ->description('Códigos e descrições por operação (EXPO, IMPO, …).')
            ->recordUrl(fn (ConfigNfseOperacao $record) => NfseOperationResource::getUrl('edit', ['record' => $record]))
            ->headerActions([
                Action::make('create')
                    ->label('Nova operação')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(NfseOperationResource::getUrl('create')),
            ]);
    }

    public function save(): void
    {
        ConfigNfse::current()
            ->fill($this->form->getState())
            ->save();

        Notification::make()
            ->success()
            ->title('Configuração NFSe alterada com sucesso.')
            ->send();
    }
}

<?php

namespace App\Filament\Resources\Charges\Schemas;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\NfseApi;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\Cobranca\Nfse\SubstituteNfse;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\DB;

/**
 * NfseEmissaoForm + _nfseEmissao (legado): bloco "Emissão de NFS-e" das baixas, do Gerar NFSe, da substituição
 * e do reprocessamento. Os campos ficam em data['nfse'] (nomes do legado: pessoa_id, cpf_cnpj, endereco_cep...).
 * Substituição e reprocessamento pedem também endereço e contato do tomador.
 */
class NfseEmissionForm
{
    public const SETTLEMENT = 'settlement';

    public const GENERATE = 'generate';

    public const SUBSTITUTE = 'substitute';

    public const REPROCESS = 'reprocess';

    private const KEYS = ['emitir_nfse', 'config_nfse_operacao_id', 'pessoa_id', 'tipo', 'cpf_cnpj', 'nome', 'descricao_servico', 'valor_servicos', 'competencia', 'c_motivo', 'x_motivo', 'valor_origem'];

    /** createForCharge / createForSubstituicao / createForReprocessamento: valores iniciais. */
    public static function defaults(FinCharges $charge, string $mode, ?FinChargesNfse $origin = null): array
    {
        $taker = app(ServiceTaker::class);
        $issue = app(IssueNfse::class);
        $fromOrigin = $origin ? $issue->defaultsFromNfse($origin) : [];
        $default = $taker->defaultsOrError($charge);
        $operation = $issue->operation($charge, $fromOrigin);

        $values = array_intersect_key([
            ...$default,
            'pessoa_id' => $taker->defaultOrigin($charge),
            'emitir_nfse' => $mode !== self::SETTLEMENT
                || (! FinChargesNfse::hasActiveEmission($charge->id) && empty($default['erro']) && ! ServiceTaker::blockedByRule($charge->embarque)),
            'config_nfse_operacao_id' => $operation?->config_nfse_operacao_id,
            'descricao_servico' => (string) $operation?->descricao_servico,
            'valor_servicos' => number_format(IssueNfse::serviceValue($charge), 2, ',', '.'),
            'competencia' => now()->format('d/m/Y'),
            ...$fromOrigin,
            ...($mode === self::SUBSTITUTE ? ['c_motivo' => '99'] : []),
        ], array_flip(self::KEYS));

        // reemissão, reprocessamento e substituição levam endereço e contato (aplicarEnderecoReprocessamento)
        if (! in_array($mode, [self::GENERATE, self::SUBSTITUTE, self::REPROCESS], true)) {
            return $values;
        }

        // endereço da nota; vazio, o do cliente ou o da OpenCNPJ (BL)
        $takerOrigin = (string) ($values['pessoa_id'] ?? '') ?: (string) ($fromOrigin['origem'] ?? '');
        $meta = $taker->meta($charge, withAddress: $takerOrigin === ServiceTaker::BL);
        $address = $fromOrigin['endereco'] ?? [];

        if (ServiceTaker::emptyAddress($address)) {
            $address = $meta[$takerOrigin]['endereco'] ?? [];
        }

        if (! empty($meta[ServiceTaker::BL]['erro_endereco'])) {
            Notification::make()->title($meta[ServiceTaker::BL]['erro_endereco'])->warning()->send();
        }

        $contact = $taker->completeContact([
            'pessoa_id' => $takerOrigin,
            'tipo' => $values['tipo'] ?? 'pj',
            'cpf_cnpj' => $values['cpf_cnpj'] ?? '',
            'telefone' => $fromOrigin['telefone'] ?? '',
            'email' => $fromOrigin['email'] ?? '',
            ...(isset($meta[ServiceTaker::CLIENT]['ref_pessoa_id']) && $takerOrigin === ServiceTaker::CLIENT ? ['ref_pessoa_id' => $meta[ServiceTaker::CLIENT]['ref_pessoa_id']] : []),
        ]);

        $values = [
            ...$values,
            ...ServiceTaker::addressFormFields($address),
            'telefone' => $contact['telefone'] ?? '',
            'email' => $contact['email'] ?? '',
        ];

        if (! $origin || $mode === self::GENERATE) {
            return $values;
        }

        // aplicarValorEditavel: o valor atual do grupo quando difere do emitido
        [$original, $current] = self::originalAndCurrent($charge, $origin, (int) ($values['config_nfse_operacao_id'] ?: $origin->config_nfse_operacao_id));
        $useCurrent = $original !== null && $current !== null && $current > 0 && abs($original - $current) >= 0.005;
        $value = $useCurrent ? $current : ($original ?? $current);

        return [
            ...$values,
            'valor_servicos' => $value !== null ? number_format($value, 2, ',', '.') : null,
            'valor_origem' => $useCurrent ? 'atual' : 'original',
        ];
    }

    /** @return list<Component> */
    public static function fields(FinCharges $charge, string $mode, ?FinChargesNfse $origin = null): array
    {
        $withAddress = in_array($mode, [self::GENERATE, self::SUBSTITUTE, self::REPROCESS], true);
        $editableValue = in_array($mode, [self::SUBSTITUTE, self::REPROCESS], true);
        $blocked = ServiceTaker::blockedByRule($charge->embarque);
        $enabled = fn (Get $get) => $mode !== self::SETTLEMENT || (bool) $get('emitir_nfse');
        $issue = app(IssueNfse::class);
        $active = FinChargesNfse::hasActiveEmission($charge->id);
        $default = $mode === self::SETTLEMENT ? app(ServiceTaker::class)->defaultsOrError($charge) : [];
        $apiUrl = NfseApi::credentials()['url'];

        return [
            Group::make()
                ->statePath('nfse')
                ->schema([
                    Callout::make('URL da API (emissor NFSe)')
                        ->description($apiUrl !== '' ? $apiUrl : 'não configurada')
                        ->color($apiUrl !== '' ? 'gray' : 'danger'),
                    Callout::make('Emissão de NFSe bloqueada pela regra do cliente.')
                        ->danger()
                        ->visible($mode === self::SETTLEMENT && $blocked),
                    Callout::make('NFSe já emitida para esta cobrança')
                        ->description('A nota saiu na instrução de cobrança (prazo/boleto); a baixa não emite outra.')
                        ->info()
                        ->visible($mode === self::SETTLEMENT && $active),
                    Callout::make('Tomador padrão inconsistente')
                        ->description(($default['erro'] ?? '').' Você pode selecionar outra origem disponível ou corrigir os dados na origem.')
                        ->warning()
                        ->visible($mode === self::SETTLEMENT && ! $blocked && ! empty($default['erro'])),
                    Callout::make($origin && $editableValue ? ($mode === self::SUBSTITUTE ? 'Substituindo' : 'Reprocessando')." a NFS-e {$origin->id_externo}" : null)
                        ->description($origin ? match ($mode) {
                            self::SUBSTITUTE => 'A Receita cancela a nota original automaticamente (evento 105102) ao autorizar a substituta.'
                                .($origin->chave_acesso ? " Chave: {$origin->chave_acesso}" : ''),
                            self::REPROCESS => 'Uma nova NFS-e será enviada com os dados corrigidos (novo id externo). A tentativa rejeitada permanece no histórico. Status: '
                                .$origin->status.'. '.($origin->readableErrors() ? 'Erros da tentativa anterior: '.implode('; ', $origin->readableErrors()) : ''),
                            default => null,
                        } : null)
                        ->warning()
                        ->visible($origin && $editableValue),
                    Callout::make('Outras NFS-e desta IC estão com valor desatualizado')
                        ->description(fn () => collect($origin && $mode === self::SUBSTITUTE ? app(SubstituteNfse::class)->outdatedNotes($charge, $origin) : [])
                            ->map(fn ($n) => "{$n['id_externo']} ({$n['name']}): emitida R$ ".number_format($n['original'], 2, ',', '.').' — atual R$ '.number_format($n['current'], 2, ',', '.'))
                            ->implode('; ').'. Substitua também essas notas.')
                        ->warning()
                        ->visible(fn () => $origin && $mode === self::SUBSTITUTE && app(SubstituteNfse::class)->outdatedNotes($charge, $origin) !== []),
                    Checkbox::make('emitir_nfse')
                        ->label('Emitir NFSe')
                        ->live()
                        ->visible($mode === self::SETTLEMENT && ! $blocked),
                    Grid::make(2)
                        ->visible($enabled)
                        ->schema([
                            Select::make('c_motivo')
                                ->label('Motivo da substituição')
                                ->options(FinChargesNfse::SUBSTITUTION_REASONS)
                                ->required()
                                ->live()
                                ->visible($mode === self::SUBSTITUTE),
                            Textarea::make('x_motivo')
                                ->label('Justificativa')
                                ->helperText('Obrigatória no motivo 99; entre 15 e 255 caracteres.')
                                ->required(fn (Get $get) => $get('c_motivo') === '99')
                                ->minLength(15)
                                ->maxLength(255)
                                ->rows(2)
                                ->visible($mode === self::SUBSTITUTE),
                            Select::make('config_nfse_operacao_id')
                                ->label('Tipo de operação')
                                ->options(IssueNfse::operationChoices())
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Get $get, Set $set) use ($charge, $origin, $editableValue) {
                                    $set('descricao_servico', (string) DB::table('config_nfse_operacao')
                                        ->where('config_nfse_operacao_id', $state)
                                        ->value('descricao_servico'));

                                    if ($origin && $editableValue) {
                                        self::applyValueOrigin($charge, $origin, (int) $state, $get, $set);
                                    }
                                })
                                ->columnSpanFull(),
                            Select::make('pessoa_id')
                                ->label('Tomador')
                                ->options(fn () => app(ServiceTaker::class)->choices($charge))
                                ->live()
                                ->afterStateUpdated(fn ($state, Set $set) => self::fillTaker($charge, (string) $state, $set, $withAddress))
                                ->columnSpanFull(),
                            Select::make('tipo')
                                ->label('Tipo')
                                ->options(['pj' => 'PJ — Pessoa Jurídica', 'pf' => 'PF — Pessoa Física'])
                                ->default('pj')
                                ->live()
                                ->required(),
                            TextInput::make('cpf_cnpj')
                                ->label('CPF/CNPJ')
                                ->live(debounce: 500)
                                ->required()
                                ->rule(fn (Get $get) => function (string $attribute, $value, $fail) use ($get) {
                                    $valid = $get('tipo') === 'pf' ? ServiceTaker::validCpf((string) $value) : ServiceTaker::validCnpj((string) $value);

                                    if (! $valid) {
                                        $fail('Informe CPF (11 dígitos) ou CNPJ (14 caracteres, numérico ou alfanumérico).');
                                    }
                                }),
                            Callout::make('Documento do tomador')
                                ->description(fn (Get $get) => self::documentWarning($get))
                                ->warning()
                                ->visible(fn (Get $get) => self::documentWarning($get) !== null)
                                ->columnSpanFull(),
                            Textarea::make('nome')
                                ->label('Razão social / Nome')
                                ->required()
                                ->maxLength(255)
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                    Fieldset::make('Contato do tomador')
                        ->visible($withAddress)
                        ->schema([
                            TextInput::make('telefone')
                                ->label('Telefone')
                                ->maxLength(30),
                            TextInput::make('email')
                                ->label('E-mail')
                                ->email(),
                        ]),
                    Fieldset::make('Endereço do tomador')
                        ->visible($withAddress)
                        ->columns(3)
                        ->schema([
                            TextInput::make('endereco_cep')
                                ->label('CEP')
                                ->mask('99999-999')
                                ->required()
                                ->regex('/^\d{5}-?\d{3}$/')
                                ->validationMessages(['regex' => 'CEP do tomador deve ter 8 dígitos.'])
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn ($state, Set $set) => self::fillCep((string) $state, $set)),
                            TextInput::make('endereco_logradouro')
                                ->label('Logradouro')
                                ->required()
                                ->maxLength(255)
                                ->columnSpan(2),
                            TextInput::make('endereco_numero')
                                ->label('Número')
                                ->helperText('Use S/N quando não houver número.')
                                ->required()
                                ->maxLength(20),
                            TextInput::make('endereco_bairro')
                                ->label('Bairro')
                                ->required()
                                ->maxLength(60)
                                ->validationMessages(['max' => 'Bairro do tomador deve ter no máximo 60 caracteres.']),
                            TextInput::make('endereco_uf')
                                ->label('UF')
                                ->required()
                                ->length(2)
                                ->regex('/^[A-Za-z]{2}$/'),
                            TextInput::make('endereco_codigo_municipio')
                                ->label('Código IBGE')
                                ->helperText('Preenchido pelo CEP ou pela busca de município (ignora acentos).')
                                ->required()
                                ->regex('/^\d{7}$/')
                                ->suffixAction(self::searchCityAction()),
                        ]),
                    Grid::make(2)
                        ->visible($enabled)
                        ->schema([
                            Textarea::make('descricao_servico')
                                ->label('Descrição do serviço')
                                ->rows(3)
                                ->columnSpanFull(),
                            Group::make()
                                ->visible($origin && $editableValue)
                                ->schema(fn () => $origin ? self::valueChoice($charge, $origin, $mode) : []),
                            TextInput::make('valor_servicos')
                                ->label('Valor dos serviços')
                                ->prefix('R$')
                                ->readOnly(! $editableValue),
                            TextInput::make('competencia')
                                ->label('Data de competência')
                                ->placeholder('dd/mm/aaaa')
                                ->mask('99/99/9999')
                                ->required($mode === self::REPROCESS)
                                ->regex('/^\d{2}\/\d{2}\/\d{4}$/'),
                        ]),
                ]),
        ];
    }

    /** Troca do tomador no select: preenche documento/nome (e contato/endereço na substituição e no reprocessamento). */
    private static function fillTaker(FinCharges $charge, string $origin, Set $set, bool $withAddress): void
    {
        $meta = app(ServiceTaker::class)->meta($charge, withAddress: $withAddress && $origin === ServiceTaker::BL)[$origin] ?? null;

        if (! $meta) {
            return;
        }

        $set('tipo', $meta['tipo']);
        $set('cpf_cnpj', $meta['cpf_cnpj']);
        $set('nome', $meta['nome']);

        if ($withAddress) {
            $set('telefone', $meta['telefone'] ?? '');
            $set('email', $meta['email'] ?? '');

            foreach (ServiceTaker::addressFormFields($meta['endereco'] ?? []) as $field => $value) {
                $set($field, $value);
            }
        }

        if (! empty($meta['erro'])) {
            Notification::make()->title($meta['erro'])->warning()->send();
        }
    }

    /** validarCep: completa logradouro, bairro, UF e IBGE pelo CEP. */
    private static function fillCep(string $cep, Set $set): void
    {
        if (strlen(preg_replace('/\D+/', '', $cep)) !== 8) {
            return;
        }

        $found = app(AddressLookup::class)->cep($cep);

        if (! $found) {
            Notification::make()->title('CEP não encontrado. Verifique o número informado.')->warning()->send();

            return;
        }

        $set('endereco_logradouro', $found['logradouro']);
        $set('endereco_bairro', $found['bairro']);
        $set('endereco_uf', $found['uf']);
        $set('endereco_codigo_municipio', $found['codigo_municipio']);
    }

    /** @return array{?float, ?float} valor emitido na nota e valor atual da fatia da operação na IC */
    private static function originalAndCurrent(FinCharges $charge, FinChargesNfse $origin, int $operationId): array
    {
        return [
            is_numeric($origin->valor_servicos) ? round((float) $origin->valor_servicos, 2) : null,
            app(IssueNfse::class)->currentValue($charge, $operationId),
        ];
    }

    /** A operação escolhida no formulário; a da nota enquanto nada foi escolhido. */
    private static function chosenOperation(FinChargesNfse $origin, Get $get): int
    {
        return (int) ($get('config_nfse_operacao_id') ?: $origin->config_nfse_operacao_id);
    }

    /** aplicarOrigemValorSubst (JS do legado): reaplica a opção escolhida; sem valor atual na operação, volta ao original. */
    private static function applyValueOrigin(FinCharges $charge, FinChargesNfse $origin, int $operationId, Get $get, Set $set): void
    {
        [$original, $current] = self::originalAndCurrent($charge, $origin, $operationId);
        $choice = $get('valor_origem');

        if ($choice === 'atual' && $current === null) {
            $choice = 'original';
            $set('valor_origem', 'original');
        }

        $value = $choice === 'atual' ? $current : $original;

        if ($value !== null) {
            $set('valor_servicos', number_format($value, 2, ',', '.'));
        }
    }

    /** Radios "valor da nota original/rejeitada" × "valor atual da cobrança"; escolher um preenche o campo de valor. @return list<Component> */
    private static function valueChoice(FinCharges $charge, FinChargesNfse $origin, string $mode): array
    {
        $brl = fn (?float $v) => $v === null ? '—' : 'R$ '.number_format($v, 2, ',', '.');

        return [
            Radio::make('valor_origem')
                ->label('Valor dos serviços')
                ->options(function (Get $get) use ($charge, $origin, $mode, $brl) {
                    [$original, $current] = self::originalAndCurrent($charge, $origin, self::chosenOperation($origin, $get));

                    return [
                        'original' => ($mode === self::REPROCESS ? 'Valor da tentativa rejeitada' : 'Valor da NFSE Original').' ('.$brl($original).')',
                        'atual' => 'Usar valor atual da Cobrança ('.$brl($current).')',
                    ];
                })
                ->disableOptionWhen(fn (string $value, Get $get) => $value === 'atual'
                    && self::originalAndCurrent($charge, $origin, self::chosenOperation($origin, $get))[1] === null)
                ->helperText(function (Get $get) use ($charge, $origin, $brl) {
                    [$original, $current] = self::originalAndCurrent($charge, $origin, self::chosenOperation($origin, $get));

                    return $original !== null && $current !== null && abs($original - $current) >= 0.005
                        ? 'A fatia desta operação mudou: '.$brl($original).' → '.$brl($current).'.'
                        : null;
                })
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::applyValueOrigin($charge, $origin, self::chosenOperation($origin, $get), $get, $set)),
        ];
    }

    /** Aviso do documento do tomador conforme tipo e origem (vazio só avisa em "Digitar tomador"). */
    private static function documentWarning(Get $get): ?string
    {
        return ServiceTaker::documentWarning((string) ($get('tipo') ?: 'pj'), (string) $get('cpf_cnpj'), manual: $get('pessoa_id') === ServiceTaker::MANUAL);
    }

    /** "Buscar (IBGE)" do legado: município pelo nome (sem acentos); preenche o código IBGE e a UF. */
    private static function searchCityAction(): Action
    {
        return Action::make('searchCity')
            ->label('Buscar (IBGE)')
            ->icon('heroicon-o-magnifying-glass')
            ->modalHeading('Buscar município (IBGE)')
            ->modalWidth('md')
            ->schema([
                Select::make('city')
                    ->label('Município')
                    ->placeholder('Nome da cidade (mín. 2 letras)')
                    ->searchable()
                    ->searchDebounce(350)
                    ->required()
                    ->getSearchResultsUsing(fn (string $search) => collect(app(AddressLookup::class)->searchCities($search))
                        ->mapWithKeys(fn (array $c) => [$c['id'] => "{$c['nome']}".($c['uf'] ? " / {$c['uf']}" : '')." ({$c['id']})"])
                        ->all()),
            ])
            ->action(function (array $data, Set $set) {
                $city = app(AddressLookup::class)->city((string) $data['city']);

                $set('endereco_codigo_municipio', $data['city']);

                if ($city['uf'] ?? null) {
                    $set('endereco_uf', $city['uf']);
                }
            });
    }
}

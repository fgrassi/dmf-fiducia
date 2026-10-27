<?php

namespace App\Filament\Resources\Charges\Schemas;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\Cobranca\Nfse\SubstituteNfse;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Filament\Forms\Components\Checkbox;
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

    private const KEYS = ['emitir_nfse', 'config_nfse_operacao_id', 'pessoa_id', 'tipo', 'cpf_cnpj', 'nome', 'descricao_servico', 'valor_servicos', 'competencia', 'c_motivo', 'x_motivo'];

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

        // Gerar NFSe parte da última nota (sem endereço); substituição e reprocessamento levam endereço e contato
        if (! $origin || ! in_array($mode, [self::SUBSTITUTE, self::REPROCESS], true)) {
            return $values;
        }

        // aplicarEnderecoReprocessamento: endereço da nota; vazio, o do cliente ou o da OpenCNPJ (BL)
        $takerOrigin = (string) ($values['pessoa_id'] ?? '') ?: (string) ($fromOrigin['origem'] ?? '');
        $meta = $taker->meta($charge, withAddress: $takerOrigin === ServiceTaker::BL);
        $address = $fromOrigin['endereco'] ?? [];

        if (ServiceTaker::emptyAddress($address)) {
            $address = $meta[$takerOrigin]['endereco'] ?? [];
        }

        $contact = $taker->completeContact([
            'pessoa_id' => $takerOrigin,
            'tipo' => $values['tipo'] ?? 'pj',
            'cpf_cnpj' => $values['cpf_cnpj'] ?? '',
            'telefone' => $fromOrigin['telefone'] ?? '',
            'email' => $fromOrigin['email'] ?? '',
            ...(isset($meta[ServiceTaker::CLIENT]['ref_pessoa_id']) && $takerOrigin === ServiceTaker::CLIENT ? ['ref_pessoa_id' => $meta[ServiceTaker::CLIENT]['ref_pessoa_id']] : []),
        ]);

        // aplicarValorEditavel: o valor atual do grupo quando difere do emitido
        $original = is_numeric($origin->valor_servicos) ? round((float) $origin->valor_servicos, 2) : null;
        $current = $issue->currentValue($charge, (int) ($values['config_nfse_operacao_id'] ?: $origin->config_nfse_operacao_id));
        $useCurrent = $original !== null && $current !== null && $current > 0 && abs($original - $current) >= 0.005;
        $value = $useCurrent ? $current : ($original ?? $current);

        return [
            ...$values,
            ...ServiceTaker::addressFormFields($address),
            'telefone' => $contact['telefone'] ?? '',
            'email' => $contact['email'] ?? '',
            'valor_servicos' => $value !== null ? number_format($value, 2, ',', '.') : null,
        ];
    }

    /** @return list<Component> */
    public static function fields(FinCharges $charge, string $mode, ?FinChargesNfse $origin = null): array
    {
        $withAddress = in_array($mode, [self::SUBSTITUTE, self::REPROCESS], true);
        $enabled = fn (Get $get) => $mode !== self::SETTLEMENT || (bool) $get('emitir_nfse');
        $issue = app(IssueNfse::class);
        $active = FinChargesNfse::hasActiveEmission($charge->id);
        $default = $mode === self::SETTLEMENT ? app(ServiceTaker::class)->defaultsOrError($charge) : [];

        return [
            Group::make()
                ->statePath('nfse')
                ->schema([
                    Callout::make('Emissão de NFSe bloqueada pela regra do cliente.')
                        ->danger()
                        ->visible($mode === self::SETTLEMENT && ServiceTaker::blockedByRule($charge->embarque)),
                    Callout::make('NFSe já emitida para esta cobrança')
                        ->description('A nota saiu na instrução de cobrança (prazo/boleto); a baixa não emite outra.')
                        ->info()
                        ->visible($mode === self::SETTLEMENT && $active),
                    Callout::make('Tomador padrão com problema')
                        ->description($default['erro'] ?? null)
                        ->warning()
                        ->visible($mode === self::SETTLEMENT && ! empty($default['erro'])),
                    Callout::make($origin ? ($mode === self::SUBSTITUTE ? 'Substituindo' : 'Reprocessando')." a NFS-e {$origin->id_externo}" : null)
                        ->description($origin && $mode === self::REPROCESS ? implode(' ', $origin->readableErrors()) ?: null : null)
                        ->warning()
                        ->visible((bool) $origin),
                    Callout::make('Outras NFS-e desta IC estão com valor desatualizado')
                        ->description(fn () => collect($origin && $mode === self::SUBSTITUTE ? app(SubstituteNfse::class)->outdatedNotes($charge, $origin) : [])
                            ->map(fn ($n) => "{$n['id_externo']} ({$n['name']}): emitida R$ ".number_format($n['original'], 2, ',', '.').' — atual R$ '.number_format($n['current'], 2, ',', '.'))
                            ->implode('; ').'. Substitua também essas notas.')
                        ->warning()
                        ->visible(fn () => $origin && $mode === self::SUBSTITUTE && app(SubstituteNfse::class)->outdatedNotes($charge, $origin) !== []),
                    Checkbox::make('emitir_nfse')
                        ->label('Emitir NFSe')
                        ->live()
                        ->visible($mode === self::SETTLEMENT),
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
                                ->afterStateUpdated(fn ($state, Set $set) => $set('descricao_servico', (string) DB::table('config_nfse_operacao')
                                    ->where('config_nfse_operacao_id', $state)
                                    ->value('descricao_servico')))
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
                                ->required(),
                            TextInput::make('cpf_cnpj')
                                ->label('CPF/CNPJ')
                                ->required()
                                ->rule(fn (Get $get) => function (string $attribute, $value, $fail) use ($get) {
                                    $valid = $get('tipo') === 'pf' ? ServiceTaker::validCpf((string) $value) : ServiceTaker::validCnpj((string) $value);

                                    if (! $valid) {
                                        $fail('Informe CPF (11 dígitos) ou CNPJ (14 caracteres, numérico ou alfanumérico).');
                                    }
                                }),
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
                                ->maxLength(100),
                            TextInput::make('endereco_uf')
                                ->label('UF')
                                ->required()
                                ->length(2)
                                ->regex('/^[A-Za-z]{2}$/'),
                            TextInput::make('endereco_codigo_municipio')
                                ->label('Código IBGE')
                                ->helperText('Preenchido pelo CEP.')
                                ->required()
                                ->regex('/^\d{7}$/'),
                        ]),
                    Grid::make(2)
                        ->visible($enabled)
                        ->schema([
                            Textarea::make('descricao_servico')
                                ->label('Descrição do serviço')
                                ->rows(3)
                                ->columnSpanFull(),
                            TextInput::make('valor_servicos')
                                ->label('Valor dos serviços')
                                ->prefix('R$')
                                ->readOnly(! $withAddress)
                                ->helperText($origin && $withAddress ? self::valueHint($charge, $origin, $issue) : null),
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

    private static function valueHint(FinCharges $charge, FinChargesNfse $origin, IssueNfse $issue): string
    {
        $current = $issue->currentValue($charge, (int) $origin->config_nfse_operacao_id);
        $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');

        return 'Valor emitido: '.(is_numeric($origin->valor_servicos) ? $brl($origin->valor_servicos) : '—')
            .' · valor atual da IC: '.($current !== null ? $brl($current) : '—');
    }
}

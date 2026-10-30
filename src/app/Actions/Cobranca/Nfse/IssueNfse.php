<?php

namespace App\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use DomainException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * NfseEmissaoService::emitir + processarEmissaoNfse (legado): monta a DPS (tomador, serviço, ISS, PIS/COFINS, tributos
 * aproximados e IBS/CBS), envia à API e grava a fin_charges_nfse. Com operações fatiadas, uma nota por grupo de despesas.
 *
 * @phpstan-type Outcome array{records:list<FinChargesNfse>, message:string, status:string}
 */
class IssueNfse
{
    public const SETTLEMENT = 'baixa';

    public const REISSUE = 'reemissao';

    public function __construct(
        private NfseApi $api,
        private ServiceTaker $taker,
        private EmissionGroups $groups,
        private SendAuthorizationEmail $email,
    ) {}

    /**
     * resolverPodeGerarNfse: botão "Gerar NFSe" — sem bloqueio do cliente, emissão antes da baixa permitida
     * e algum grupo (ou a IC) ainda sem emissão ativa.
     */
    public function canGenerate(FinCharges $charge): bool
    {
        if (ServiceTaker::blockedByRule($charge->embarque) || ! self::canIssueBeforeSettlement($charge)) {
            return false;
        }

        try {
            $groups = ($this->groups)($charge);
        } catch (DomainException) {
            $groups = [];
        }

        if ($groups === []) {
            return ! FinChargesNfse::hasActiveEmission($charge->id);
        }

        $active = FinChargesNfse::activeOperations($charge->id);

        return collect($groups)->contains(fn ($g) => ! isset($active[$g['operation_id']]));
    }

    /** podeEmitirNfseSemBaixa: em aberto, só com prazo/boleto, armador que não recalcula ou cliente em exceção. */
    public static function canIssueBeforeSettlement(FinCharges $charge): bool
    {
        return $charge->status !== ChargeStatus::Open
            || EmissionGroups::issuedWithInstruction($charge->embarque)
            || self::clientInException($charge);
    }

    /** clienteEmExcecaoPorEmbarque: FinClientsException, salvo armador que não recalcula. */
    public static function clientInException(FinCharges $charge): bool
    {
        $e = $charge->embarque;

        return ! EmissionGroups::carrierDoesNotRecalculate($e)
            && DB::table('fin_clients_exception')->where('ref_client', ChargeRates::clientId($e))->exists();
    }

    /**
     * processarEmissaoNfse: emite a NFS-e marcada no formulário (uma por grupo, pulando operações já emitidas).
     * Nunca interrompe a baixa: erros voltam na mensagem.
     *
     * @param  ?list<array>  $groups  grupos já calculados pelos recibos (null = calcula)
     * @return ?Outcome null = nada a emitir
     */
    public function forCharge(FinCharges $charge, array $data, string $context, ?int $userId, ?array $groups = null): ?array
    {
        if (empty($data['emitir_nfse'])) {
            return null;
        }

        $prefix = $context === self::REISSUE ? 'Reemissão NFSe' : 'Baixa concluída';

        try {
            // na baixa: se a NF já saiu com a instrução (prazo/boleto), não reemite
            if ($context === self::SETTLEMENT && FinChargesNfse::hasActiveEmission($charge->id)) {
                return ['records' => [], 'status' => 'success', 'message' => 'Baixa concluída — NFSe já emitida na instrução de cobrança; recibo enviado normalmente.'];
            }

            // sem documento no formulário (e não digitado à mão), usa o tomador do BL
            if (($data['pessoa_id'] ?? '') !== ServiceTaker::MANUAL && (blank($data['cpf_cnpj'] ?? null) || blank($data['nome'] ?? null))) {
                try {
                    $data = [...$this->taker->fromBl($charge), ...array_filter($data, fn ($v) => filled($v))];
                } catch (DomainException) {
                    // segue com o formulário; a validação do tomador explica o problema
                }
            }

            $groups ??= ($this->groups)($charge);

            if ($groups === []) {
                $record = $this->issue($charge, $data, $userId);
                $failed = in_array($record->status, FinChargesNfse::FAILED_STATUSES, true);

                return ['records' => [$record], 'status' => $failed ? 'warning' : 'success', 'message' => $failed
                    ? ($context === self::REISSUE ? 'A reemissão da NFSe falhou. Verifique os erros e tente novamente.' : 'Baixa concluída, mas a emissão da NFSe falhou. Você pode tentar novamente na tela da cobrança.')
                    : "{$prefix} — NFSe enviada (status: {$record->status})."];
            }

            $skip = match ($context) {
                self::REISSUE => FinChargesNfse::activeOperations($charge->id, authorizedOnly: true),
                default => FinChargesNfse::activeOperations($charge->id),
            };
            $records = [];
            $skipped = 0;

            foreach ($groups as $group) {
                if (isset($skip[$group['operation_id']])) {
                    $skipped++;

                    continue;
                }

                $operation = self::operationById($group['operation_id']);
                // fatiado: cada nota leva a descrição da própria operação
                $records[] = $this->issue($charge, ['descricao_servico' => (string) $operation->descricao_servico] + $data, $userId, $operation, $group['total'], sendEmail: false);
            }

            if ($records !== []) {
                ($this->email)($charge);
            }

            $errors = collect($records)->filter(fn ($r) => in_array($r->status, FinChargesNfse::FAILED_STATUSES, true))->count();
            $extra = $skipped > 0 ? " ({$skipped} já emitida(s) pulada(s))" : '';

            return ['records' => $records] + match (true) {
                $records === [] && $skipped > 0 => ['status' => 'success', 'message' => "{$prefix} — todas as NFSe dos grupos já estão emitidas/autorizadas."],
                $records === [] => ['status' => 'warning', 'message' => "{$prefix} — nenhum grupo pôde ser emitido."],
                $errors === count($records) => ['status' => 'warning', 'message' => $context === self::REISSUE
                    ? 'A reemissão das NFSe falhou. Verifique os erros e tente novamente.'
                    : 'Baixa concluída, mas a emissão das NFSe falhou. Você pode tentar novamente na tela da cobrança.'],
                $errors > 0 => ['status' => 'warning', 'message' => "{$prefix} — algumas NFSe falharam ({$errors} de ".count($records).").{$extra}"],
                default => ['status' => 'success', 'message' => "{$prefix} — ".count($records).' NFSe enviadas (status: '.end($records)->status.").{$extra}"],
            };
        } catch (Throwable $e) {
            report($e);

            return ['records' => [], 'status' => 'warning', 'message' => $context === self::REISSUE
                ? "Erro ao reemitir NFSe: {$e->getMessage()}"
                : "Baixa concluída, mas ocorreu erro ao emitir NFSe: {$e->getMessage()}"];
        }
    }

    /**
     * emitirNaGeracaoCobranca (legado), depois das checagens de cliente: tomador padrão e uma nota por grupo de
     * despesas (pulando operações já emitidas). Com $sendEmail o cliente recebe o e-mail da autorização.
     *
     * @return ?string erro (status das notas que falharam ou a exceção)
     */
    public function withInstruction(FinCharges $charge, ?int $userId, bool $sendEmail): ?string
    {
        try {
            $taker = $this->taker->default($charge);
            $this->taker->validate($taker);
            $data = [...$taker, 'emitir_nfse' => true];
            $groups = ($this->groups)($charge);

            if ($groups === []) {
                $records = [$this->issue($charge, $data, $userId, sendEmail: $sendEmail)];
            } else {
                $active = FinChargesNfse::activeOperations($charge->id);
                $records = collect($groups)
                    ->reject(fn ($g) => isset($active[$g['operation_id']]))
                    ->map(function ($g) use ($charge, $data, $userId) {
                        $operation = self::operationById($g['operation_id']);

                        return $this->issue($charge, ['descricao_servico' => (string) $operation->descricao_servico] + $data, $userId, $operation, $g['total'], sendEmail: false);
                    })
                    ->values()
                    ->all();

                if ($sendEmail && $records !== []) {
                    ($this->email)($charge);
                }
            }

            $errors = collect($records)->filter(fn ($r) => in_array($r->status, FinChargesNfse::FAILED_STATUSES, true))
                ->map(fn ($r) => "status={$r->status} {$r->erros_json}");

            return $errors->isEmpty() ? null : $errors->implode('; ');
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }
    }

    /** emitir: uma NFS-e. Falha de HTTP grava a nota como erro_http (com os erros) em vez de lançar. */
    public function issue(FinCharges $charge, array $data, ?int $userId, ?stdClass $operation = null, ?float $value = null, ?string $competence = null, bool $sendEmail = true): FinChargesNfse
    {
        if (ServiceTaker::blockedByRule($charge->embarque)) {
            throw new DomainException('Emissão de NFSe bloqueada pela regra do cliente.');
        }

        $operation ??= $this->operation($charge, $data) ?? throw new DomainException('Selecione o tipo de operação NFSe (EXPO/IMPO) antes de emitir.');

        ServiceTaker::validateTypedContact($data);
        $taker = $this->taker->completeContact($this->taker->completeAddress($this->taker->extract($data)));
        $this->taker->validate($taker);

        $externalId = $this->nextExternalId($charge);
        $payload = $this->payload($charge, $operation, $taker, trim((string) ($data['descricao_servico'] ?? '')) ?: (string) $operation->descricao_servico, $externalId, $value, $competence ?? ($data['competencia'] ?? null));

        // gravada só com a resposta da API, como no legado: sem resposta não fica nota "enfileirada" travando a IC
        $record = (new FinChargesNfse)->forceFill([
            'ref_charge' => $charge->id,
            'config_nfse_operacao_id' => $operation->config_nfse_operacao_id,
            'id_externo' => $externalId,
            'payload_json' => json_encode($payload),
            'tomador_json' => json_encode($taker),
            'valor_servicos' => self::serviceValue($charge, $value),
            'status' => 'enfileirada',
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->applyResponse($record, $this->api->issue($payload), 'Falha ao emitir NFSe');

        if ($sendEmail) {
            ($this->email)($charge);
        }

        return $record;
    }

    /**
     * reprocessar: nova emissão de uma nota rejeitada/com erro, com competência e valor do formulário.
     *
     * @return array{ok:bool, message:string}
     */
    public function reprocess(FinChargesNfse $origin, array $data, ?int $userId): array
    {
        if (! $origin->canReprocess()) {
            return ['ok' => false, 'message' => 'Esta NFS-e não pode ser reprocessada no momento.'];
        }

        $charge = $origin->charge;

        try {
            $this->taker->validate($this->taker->extract($data));
            $competence = self::competence($data['competencia'] ?? '');
            $operation = $this->operation($charge, $data)
                ?? ($origin->config_nfse_operacao_id ? self::operationById($origin->config_nfse_operacao_id) : null)
                ?? throw new DomainException('Selecione o tipo de operação NFSe antes de reprocessar.');
            $value = $this->formValue($charge, (int) $operation->config_nfse_operacao_id, $origin, $data)
                ?? throw new DomainException('Informe o valor dos serviços da NFS-e.');

            $record = $this->issue($charge, $data, $userId, $operation, $value, $competence);
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        return in_array($record->status, FinChargesNfse::FAILED_STATUSES, true)
            ? ['ok' => false, 'message' => 'O reprocessamento foi enviado, mas a NFS-e falhou. Verifique os erros e tente novamente.']
            : ['ok' => true, 'message' => "NFS-e reprocessada e enviada (status: {$record->status})."];
    }

    /** Resposta da API na nota: uuid/status/chave, ou erro_http com os erros. */
    public function applyResponse(FinChargesNfse $record, array $response, string $fallbackError): void
    {
        $record->response_json = $response['raw'];

        if (! $response['ok']) {
            $body = is_array($response['body']) ? $response['body'] : [];
            $errors = array_values(array_filter([
                $response['error'] ? ['codigo' => 'CURL', 'descricao' => $response['error']] : null,
                isset($body['message']) ? ['codigo' => "HTTP_{$response['http_code']}", 'descricao' => $body['message']] : null,
                isset($body['errors']) ? ['codigo' => 'VALIDATION', 'descricao' => json_encode($body['errors'])] : null,
            ])) ?: [['codigo' => "HTTP_{$response['http_code']}", 'descricao' => $fallbackError]];

            $record->forceFill(['status' => 'erro_http', 'erros_json' => json_encode($errors)])->save();

            return;
        }

        $data = is_array($response['body']) ? ($response['body']['data'] ?? $response['body']) : null;

        if (is_array($data)) {
            $record->nfse_uuid = $data['id'] ?? $record->nfse_uuid;
            $record->status = ($data['status'] ?? null) ?: 'enfileirada';
            $record->applyApiData($data);

            if (! empty($data['erros'])) {
                $record->erros_json = json_encode($data['erros']);
            }
        }

        $record->save();
    }

    /** buildPayload: DPS no formato da API. */
    public function payload(FinCharges $charge, stdClass $operation, array $taker, string $description, string $externalId, ?float $value, ?string $competence): array
    {
        $config = DB::table('config_nfse')->where('config_nfse_id', 1)->first() ?? throw new DomainException('Configuração da API NFS-e indisponível.');
        $amount = self::serviceValue($charge, $value);
        $e = $charge->embarque;
        $nbs = self::nbs($operation->codigo_nbs);
        $issqn = (string) $operation->trib_issqn !== ''
            ? (string) $operation->trib_issqn
            // 990101 = não incidência → tribISSQN 4
            : (preg_replace('/\D+/', '', (string) $operation->codigo_tributacao_nacional) === '990101' ? '4' : '1');

        $takerPayload = ['tipo' => $taker['tipo'], 'cpf_cnpj' => $taker['cpf_cnpj'], 'nome' => $taker['nome']]
            + array_filter(['telefone' => ServiceTaker::phone($taker['telefone'] ?? ''), 'email' => ServiceTaker::email($taker['email'] ?? '')]);

        if (! empty($taker['endereco'])) {
            $takerPayload['endereco'] = array_merge($taker['endereco'], [
                'logradouro' => FinChargesNfse::tsString($taker['endereco']['logradouro'] ?? '', 255),
                'numero' => FinChargesNfse::tsString($taker['endereco']['numero'] ?? '', 60),
                'bairro' => FinChargesNfse::tsString($taker['endereco']['bairro'] ?? '', 60),
            ]);
        }

        $values = ['valor_servicos' => $amount, 'trib_issqn' => $issqn, 'iss_retido' => $issqn !== '4' && (bool) $operation->iss_retido];

        // não incidência / imunidade / exportação: sem alíquota nem valor do ISS
        if ($issqn === '1' && is_numeric($operation->aliquota_iss)) {
            $values['aliquota_iss'] = (float) $operation->aliquota_iss;
            $values['valor_iss'] = round($amount * ((float) $operation->aliquota_iss / 100), 2); // como o legado: alíquota/100 antes de multiplicar (o ponto flutuante muda 1 centavo)
        }

        $values += array_filter(['pis_cofins' => self::pisCofins($operation, $amount), 'tot_trib' => self::approximateTaxes($operation)]);
        $ibsCbs = self::ibsCbs($config, $operation);

        return array_filter([
            'id_externo' => $externalId,
            'competencia' => filled($competence) ? self::competence($competence) : now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'emissor' => array_filter([
                'cnpj' => preg_replace('/\D+/', '', (string) $config->emissor_cnpj),
                'razao_social' => (string) $config->emissor_razao_social,
                'codigo_municipio' => (string) $config->emissor_codigo_municipio,
                'uf' => (string) $config->emissor_uf,
                'email' => (string) $config->emissor_email,
                'telefone' => preg_replace('/\D+/', '', (string) $config->emissor_telefone),
            ], fn ($v) => $v !== '') + ['reg_trib' => [
                'op_simp_nac' => (string) $config->op_simp_nac ?: '1',
                'reg_esp_trib' => (string) $config->reg_esp_trib !== '' ? (string) $config->reg_esp_trib : '0',
            ]],
            'tomador' => $takerPayload,
            'servico' => array_filter([
                'codigo_tributacao_nacional' => (string) $operation->codigo_tributacao_nacional,
                'descricao' => $description,
                'codigo_municipio_prestacao' => (string) $config->emissor_codigo_municipio,
                'codigo_nbs' => $nbs,
            ], fn ($v) => $v !== null),
            'valores' => $values,
            'informacoes_complementares' => FinChargesNfse::tsString('Reserva: '.(trim((string) $e->booking) ?: '-')." ({$charge->id})"
                .(filled(trim((string) $e->house_bl)) ? ' | BLs (numeros): '.trim((string) $e->house_bl) : ''), 2000),
            'webhook_url' => trim((string) $config->webhook_url),
            'serie_dps' => (string) $config->serie_dps,
            // a API exige codigo_nbs junto com ibs_cbs
            'ibs_cbs' => $nbs !== null ? $ibsCbs : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** getValorServicos: total − desconto (ou o valor do grupo). */
    public static function serviceValue(FinCharges $charge, ?float $override = null): float
    {
        if ($override !== null) {
            return max(0.0, round($override, 2));
        }

        $value = (float) $charge->total_value - (float) $charge->discount;

        return round($value > 0 ? $value : (float) $charge->total_value, 2);
    }

    /** getValoresGruposPorOperacao: total atual de cada operação fatiada. @return array<int, float> */
    public function groupValues(FinCharges $charge): array
    {
        try {
            return collect(($this->groups)($charge))->mapWithKeys(fn ($g) => [$g['operation_id'] => round($g['total'], 2)])->all();
        } catch (DomainException) {
            return [];
        }
    }

    /** getValorAtualParaSubstituicao: valor atual do grupo da operação (ou da IC inteira, sem fatiamento). */
    public function currentValue(FinCharges $charge, int $operationId): ?float
    {
        $groups = $this->groupValues($charge);

        if ($groups !== []) {
            return $groups[$operationId] ?? null;
        }

        $value = self::serviceValue($charge);

        return $value > 0 ? $value : null;
    }

    /** resolverValorOverrideForm: valor digitado, senão o atual do grupo, senão o da nota de origem. */
    public function formValue(FinCharges $charge, int $operationId, FinChargesNfse $origin, array $data): ?float
    {
        return self::money($data['valor_servicos'] ?? '')
            ?? $this->currentValue($charge, $operationId)
            ?? (is_numeric($origin->valor_servicos) && (float) $origin->valor_servicos > 0 ? round((float) $origin->valor_servicos, 2) : null);
    }

    /** resolveOperacao: a escolhida no formulário (ativa) ou a primeira do modal. */
    public function operation(FinCharges $charge, array $data = []): ?stdClass
    {
        $chosen = filled($data['config_nfse_operacao_id'] ?? null)
            ? DB::table('config_nfse_operacao')->where('config_nfse_operacao_id', (int) $data['config_nfse_operacao_id'])->where('ativo', 1)->first()
            : null;

        return $chosen ?? (in_array($charge->embarque->impor_expor, ['e', 'i'], true)
            ? DB::table('config_nfse_operacao')->where('impor_expor', $charge->embarque->impor_expor)->where('ativo', 1)->orderBy('ordem')->first()
            : null);
    }

    public static function operationById(int $id): stdClass
    {
        return DB::table('config_nfse_operacao')->where('config_nfse_operacao_id', $id)->first()
            ?? throw new DomainException("Operação NFSe #{$id} não encontrada.");
    }

    /** ConfigNfseOperacaoTable::getChoices(ativas). @return array<int, string> */
    public static function operationChoices(): array
    {
        return DB::table('config_nfse_operacao')->where('ativo', 1)->orderBy('impor_expor')->orderBy('ordem')->get()
            ->mapWithKeys(fn ($o) => [$o->config_nfse_operacao_id => collect([$o->codigo_operacao, $o->nome])->filter()->implode(' — ') ?: 'Operação NFSe'])
            ->all();
    }

    /** extractFormDefaultsFromNfse: tomador, operação, descrição e competência de uma nota já emitida. */
    public function defaultsFromNfse(FinChargesNfse $nfse): array
    {
        $payload = json_decode((string) $nfse->payload_json, true) ?: [];
        $payload = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $competence = $payload['competencia'] ?? null;

        return array_filter([
            ...(json_decode((string) $nfse->tomador_json, true) ?: []),
            'emitir_nfse' => true,
            'config_nfse_operacao_id' => $nfse->config_nfse_operacao_id,
            'descricao_servico' => $payload['servico']['descricao'] ?? null,
            'competencia' => $competence && strtotime($competence) ? date('d/m/Y', strtotime($competence)) : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** parseValorServicosInput: "1.234,56" ou "1234.56"; null se vazio/inválido/≤ 0. */
    public static function money(mixed $raw): ?float
    {
        $raw = str_replace(['R$', 'r$', ' ', "\u{a0}"], '', trim((string) $raw));

        if (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', str_replace('.', '', $raw));
        }

        return is_numeric($raw) && round((float) $raw, 2) > 0 ? round((float) $raw, 2) : null;
    }

    /** resolverCompetencia: dd/mm/aaaa ou aaaa-mm-dd → aaaa-mm-dd; vazio = hoje. */
    public static function competence(mixed $raw): string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return now()->toDateString();
        }

        [$d, $m, $y] = match (true) {
            (bool) preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $p) => [(int) $p[1], (int) $p[2], (int) $p[3]],
            (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $p) => [(int) $p[3], (int) $p[2], (int) $p[1]],
            default => [0, 0, 0],
        };

        if (! checkdate($m, $d, $y)) {
            throw new DomainException('Competência inválida. Use o formato dd/mm/aaaa.');
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    /** nextIdExterno: booking-IC, com -R{n} a partir da 2ª nota da IC (até 64 caracteres). */
    public function nextExternalId(FinCharges $charge): string
    {
        $count = FinChargesNfse::where('ref_charge', $charge->id)->count();
        $suffix = $count > 0 ? "-R{$count}" : '';
        $booking = trim((string) $charge->embarque->booking);

        return $booking !== ''
            ? substr($booking, 0, 64 - strlen("-{$charge->id}{$suffix}"))."-{$charge->id}{$suffix}"
            : substr("COB-{$charge->id}", 0, 64 - strlen($suffix)).$suffix;
    }

    private static function nbs(mixed $value): ?string
    {
        $nbs = trim((string) $value);

        return preg_match('/^\d+(\.\d+)+$/', $nbs) || preg_match('/^\d{9}$/', $nbs) ? $nbs : null;
    }

    /** buildIbsCbs: só com a operação habilitada e códigos completos (cClassTrib começa pelo CST). */
    private static function ibsCbs(stdClass $config, stdClass $operation): ?array
    {
        $digits = fn ($v) => preg_replace('/\D+/', '', (string) $v);
        [$op, $cst, $class] = [$digits($operation->ibs_cbs_c_ind_op), $digits($operation->ibs_cbs_cst), $digits($operation->ibs_cbs_c_class_trib)];

        if (! (int) $operation->ibs_cbs_habilitado || strlen($op) !== 6 || strlen($cst) !== 3 || strlen($class) !== 6 || ! str_starts_with($class, $cst)) {
            return null;
        }

        return [
            'fin_nfse' => $digits($config->ibs_cbs_fin_nfse) ?: '0',
            'ind_final' => $digits($config->ibs_cbs_ind_final) ?: '0',
            'c_ind_op' => $op,
            'cst' => $cst,
            'c_class_trib' => $class,
        ];
    }

    /** buildPisCofins: CST 08/09 levam só o código. */
    private static function pisCofins(stdClass $operation, float $amount): ?array
    {
        $cst = preg_replace('/\D+/', '', (string) $operation->pis_cofins_cst);

        if (! (int) $operation->pis_cofins_habilitado || strlen($cst) !== 2) {
            return null;
        }

        if (in_array($cst, ['08', '09'], true)) {
            return ['cst' => $cst];
        }

        $block = ['cst' => $cst, 'tipo_retencao' => (string) $operation->pis_cofins_tipo_retencao ?: '0', 'base_calculo' => round($amount, 2)];

        foreach (['pis', 'cofins'] as $tax) {
            if (is_numeric($operation->{"aliquota_{$tax}"})) {
                $block["aliquota_{$tax}"] = (float) $operation->{"aliquota_{$tax}"};
                $block["valor_{$tax}"] = round($amount * ((float) $operation->{"aliquota_{$tax}"} / 100), 2);
            }
        }

        return $block;
    }

    /** buildTotTrib: percentuais aproximados (Lei da Transparência). */
    private static function approximateTaxes(stdClass $operation): ?array
    {
        $levels = ['federal', 'estadual', 'municipal'];

        if (collect($levels)->every(fn ($l) => ! is_numeric($operation->{"tot_trib_percentual_{$l}"}))) {
            return null;
        }

        return collect($levels)->mapWithKeys(fn ($l) => ["percentual_{$l}" => (float) $operation->{"tot_trib_percentual_{$l}"}])->all();
    }
}

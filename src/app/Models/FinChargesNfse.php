<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** NFS-e da cobrança (regras do FinChargesNfse legado). */
class FinChargesNfse extends Model
{
    /** Notas com valor igual ou superior a este limite não podem ser canceladas. */
    public const CANCEL_LIMIT = 50000.00;

    /** Status em que a nota não muda mais sozinha. */
    public const FINAL_STATUSES = ['autorizada', 'rejeitada', 'erro_http', 'erro', 'cancelada', 'substituida'];

    /** Emissão ativa: autorizada ou ainda na fila (temEmissaoAtivaPorCobranca). */
    public const ACTIVE_STATUSES = ['autorizada', 'enfileirada', 'processando'];

    public const FAILED_STATUSES = ['erro_http', 'rejeitada', 'erro'];

    /** motivosSubstituicao: códigos oficiais do grupo subst da DPS. */
    public const SUBSTITUTION_REASONS = [
        '01' => '01 — Desenquadramento de NFS-e do Simples Nacional',
        '02' => '02 — Enquadramento de NFS-e no Simples Nacional',
        '03' => '03 — Inclusão retroativa de imunidade/isenção',
        '04' => '04 — Exclusão retroativa de imunidade/isenção',
        '05' => '05 — Rejeição da NFS-e pelo tomador ou intermediário',
        '99' => '99 — Outros',
    ];

    /** normalizarMotivoSubstituicao: 1 → 01; vazio se não for oficial. */
    public static function substitutionReason(mixed $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        $code = $digits === '' ? '' : str_pad($digits, 2, '0', STR_PAD_LEFT);

        return isset(self::SUBSTITUTION_REASONS[$code]) ? $code : '';
    }

    /**
     * sanitizarTsString: TSString do XSD nacional ([!-ÿ][ -ÿ]*[!-ÿ]). Travessão, aspas tipográficas e o que passa de U+00FF
     * quebram o pattern e a SEFIN rejeita com E1235.
     */
    public static function tsString(mixed $text, int $max = 255): string
    {
        $text = preg_replace('/\p{Pd}/u', '-', (string) $text) ?? (string) $text;
        $text = strtr($text, ['−' => '-', '‘' => "'", '’' => "'", '‚' => "'", '“' => '"', '”' => '"', '„' => '"', '…' => '...', '•' => '-', "\u{a0}" => ' ']);
        $text = preg_replace(['/[\r\n\t\f\v]+/', '/[\x{200B}-\x{200D}\x{FEFF}]/u', '/[^\x20-\x7E\x{A0}-\x{FF}]/u'], [' ', '', ''], $text) ?? '';

        return rtrim(mb_substr(trim(preg_replace('/ +/', ' ', $text)), 0, $max));
    }

    /** IDs de operação com emissão ativa na IC (listarOperacaoIdsComEmissaoAtiva), ou só autorizadas. @return array<int, true> */
    public static function activeOperations(int $chargeId, bool $authorizedOnly = false): array
    {
        return self::where('ref_charge', $chargeId)
            ->whereIn('status', $authorizedOnly ? ['autorizada'] : self::ACTIVE_STATUSES)
            ->where('config_nfse_operacao_id', '>', 0)
            ->pluck('config_nfse_operacao_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    public static function hasActiveEmission(int $chargeId): bool
    {
        return self::where('ref_charge', $chargeId)->whereIn('status', self::ACTIVE_STATUSES)->exists();
    }

    protected $table = 'fin_charges_nfse';

    protected $primaryKey = 'fin_charges_nfse_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'email_enviado_at' => 'datetime',
        ];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(FinCharges::class, 'ref_charge', 'id');
    }

    public function canDownload(): bool
    {
        return $this->status === 'autorizada' && filled($this->nfse_uuid);
    }

    /** podeReemitir: rejeitada ou com erro. */
    public function canReissue(): bool
    {
        return in_array($this->status, ['rejeitada', 'erro_http', 'erro'], true);
    }

    /** podeReprocessar: falhou e não há outra emissão ativa na mesma operação (ou na IC, sem operação). */
    public function canReprocess(): bool
    {
        if (! $this->canReissue()) {
            return false;
        }

        $operation = (int) $this->config_nfse_operacao_id;

        return $operation > 0
            ? ! isset(self::activeOperations((int) $this->ref_charge)[$operation])
            : ! self::hasActiveEmission((int) $this->ref_charge);
    }

    /** podeSubstituir: o limite de valor do cancelamento não vale para a substituição. */
    public function canSubstitute(): bool
    {
        return $this->isCancellable();
    }

    public function canCancel(): bool
    {
        return $this->isCancellable() && ! $this->exceedsCancelLimit();
    }

    /** bloqueadaPorLimiteCancelamento: cancelaria, não fosse o valor. */
    public function blockedByCancelLimit(): bool
    {
        return $this->isCancellable() && $this->exceedsCancelLimit();
    }

    /** podeAtualizarStatus: pendente de emissão, cancelamento ou substituição. */
    public function canRefreshStatus(): bool
    {
        if (blank($this->nfse_uuid) || in_array($this->status, ['cancelada', 'substituida'], true)) {
            return false;
        }

        return $this->status !== 'autorizada' || $this->hasPendingCancellation() || $this->hasPendingSubstitution();
    }

    public function exceedsCancelLimit(): bool
    {
        $value = $this->cancelValue();

        return $value !== null && $value >= self::CANCEL_LIMIT;
    }

    /** getValorCancelamento: valor dos serviços; sem a coluna, o do payload enviado. */
    public function cancelValue(): ?float
    {
        if (is_numeric($this->valor_servicos)) {
            return (float) $this->valor_servicos;
        }

        $data = $this->jsonData('payload_json');
        $value = $data['valores']['valor_servicos'] ?? $data['valor_servicos'] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /** Bloco "cancelamento" do último JSON da API/webhook. */
    public function apiCancellation(): ?array
    {
        $c = $this->jsonData('response_json')['cancelamento'] ?? null;

        return is_array($c) ? $c : null;
    }

    /** temCancelamentoFalhou: a Receita rejeitou o evento; a nota segue autorizada. */
    public function cancellationFailed(): bool
    {
        if (! empty($this->apiCancellation()['falhou_em'])) {
            return true;
        }

        return collect(json_decode((string) $this->erros_json, true) ?: [])
            ->contains(fn ($e) => is_array($e) && str_starts_with((string) ($e['codigo'] ?? ''), 'CANCELAMENTO_'));
    }

    public function hasPendingCancellation(): bool
    {
        if ($this->status === 'cancelada' || $this->cancellationFailed()) {
            return false;
        }

        $c = $this->apiCancellation();

        return $c !== null && (! empty($c['enfileirado_em']) || ! empty($c['c_motivo']));
    }

    /** temSubstituicaoPendente: substituta ainda não final. */
    public function hasPendingSubstitution(): bool
    {
        if ($this->nfse_substituida_id) {
            return false;
        }

        return in_array($this->activeSubstitute()?->status, ['enfileirada', 'processando', 'rascunho'], true);
    }

    public function activeSubstitute(): ?self
    {
        return self::where('nfse_substituida_id', $this->getKey())
            ->whereNotIn('status', ['rejeitada', 'erro_http', 'erro'])
            ->orderByDesc('fin_charges_nfse_id')
            ->first();
    }

    /** getErrosLegiveis. @return list<string> */
    public function readableErrors(): array
    {
        $raw = trim((string) $this->erros_json);

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [$raw];
        }

        return collect($decoded)->map(function ($error) {
            if (! is_array($error)) {
                return (string) $error;
            }

            $code = (string) ($error['codigo'] ?? '');
            $message = (string) (($error['descricao'] ?? '') ?: ($error['mensagem'] ?? ''));

            return $code && $message ? "{$code} — {$message}" : ($message ?: $code);
        })->filter()->values()->all();
    }

    public function publicConsultationUrl(): ?string
    {
        return filled($this->chave_acesso) ? 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave='.trim($this->chave_acesso) : null;
    }

    /** aplicarDadosApi: chave, protocolo e DANFSe (ignora o link morto da ADN, suspenso na NT 008/2026). */
    public function applyApiData(array $data): void
    {
        if (! empty($data['chave_acesso'])) {
            $this->chave_acesso = $data['chave_acesso'];
        }

        if (! empty($data['protocolo'])) {
            $this->protocolo = $data['protocolo'];
        }

        if (array_key_exists('danfse_url', $data)) {
            $url = is_string($data['danfse_url']) ? html_entity_decode(trim($data['danfse_url']), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $dead = fn (?string $u) => (bool) preg_match('#^https?://adn\.(producaorestrita\.)?nfse\.gov\.br/danfse/#i', trim((string) $u));

            if ($url !== '' && ! $dead($url)) {
                $this->danfse_url = $url;
            } elseif ($dead($url) || $dead($this->danfse_url)) {
                $this->danfse_url = null;
            }
        }
    }

    /** "elegível" comum a cancelar/substituir (sem olhar o limite de valor). */
    private function isCancellable(): bool
    {
        return $this->status === 'autorizada'
            && filled($this->nfse_uuid)
            && filled(trim((string) $this->chave_acesso))
            && ! $this->hasPendingCancellation()
            && ! $this->hasPendingSubstitution();
    }

    private function jsonData(string $column): array
    {
        $decoded = json_decode(trim((string) $this->{$column}), true);

        return is_array($decoded) ? (is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded) : [];
    }
}

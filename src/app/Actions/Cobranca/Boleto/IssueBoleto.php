<?php

namespace App\Actions\Cobranca\Boleto;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Models\FinChargesBoletoSicredi;
use App\Models\FinChargesLog;
use App\Models\FinClientsBoleto;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * SicrediBoletoService::emitir (legado): cadastra o boleto da IC na Sicredi, baixa o PDF para uploads/boleto e grava em
 * fin_charges_boleto_sicredi. Reaproveita o boleto já emitido; se o cadastro saiu e só o PDF falhou (ou o emitido está
 * sem arquivo), baixa de novo sem cadastrar outro. Um lock por cobrança impede dois cadastros do mesmo boleto (novo em relação ao legado).
 *
 * Como no legado, o boleto emitido é reaproveitado mesmo que a IC tenha sido recalculada depois: para trocar o valor,
 * baixar o boleto e emitir de novo.
 */
class IssueBoleto
{
    private const PDF_ATTEMPTS = 4;

    public function __construct(
        private SicrediApi $api,
        private BoletoPayload $payload,
    ) {}

    /**
     * @return array{boleto:FinChargesBoletoSicredi, reused:bool}
     *
     * @throws DomainException
     */
    public function __invoke(FinCharges $charge, ?int $userId, ?string $due = null): array
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Só é possível gerar boleto para cobrança em aberto.');
        }

        if (! FinClientsBoleto::usesBoleto($charge->embarque)) {
            throw new DomainException('Cliente sem flag de boleto para o modal desta cobrança (IMPO/EXPO).');
        }

        $lock = Cache::lock("sicredi-boleto-{$charge->id}", 180);

        if (! $lock->get()) {
            throw new DomainException('Já há uma emissão de boleto em andamento para esta cobrança.');
        }

        try {
            $issued = FinChargesBoletoSicredi::issuedFor($charge->id);

            if ($issued?->ref_file) {
                return ['boleto' => $issued, 'reused' => true];
            }

            $registered = FinChargesBoletoSicredi::where('ref_charge', $charge->id)->where('status', 'erro')
                ->where('linha_digitavel', '<>', '')->orderByDesc('id')->first();

            return ['boleto' => $this->attachPdf($charge, $issued ?? $registered ?? $this->register($charge, $userId, $due)), 'reused' => false];
        } finally {
            $lock->release();
        }
    }

    /**
     * processarBoletoAutomaticoNaGeracaoCobranca: emite junto com a IC para o cliente com boleto automático no modal.
     * A falha vai para o histórico da IC e não interrompe a geração nem o e-mail.
     */
    public function automatic(FinCharges $charge): void
    {
        if ($charge->status !== ChargeStatus::Open || ! FinClientsBoleto::usesAutomaticBoleto($charge->embarque)) {
            return;
        }

        try {
            if (! $this($charge, auth()->id())['reused']) {
                self::log($charge, 'Boleto Sicredi emitido automaticamente com a IC.', auth()->id());
            }
        } catch (Throwable $e) {
            if (! $e instanceof DomainException) {
                report($e);
            }

            self::log($charge, "Falha na emissão automática do boleto Sicredi: {$e->getMessage()}", auth()->id());
        }
    }

    /** Histórico da IC das ações do boleto: o status da cobrança não muda. */
    public static function log(FinCharges $charge, string $message, ?int $userId): void
    {
        FinChargesLog::forceCreate([
            'ref_charge' => $charge->id,
            'acao' => 'alteracao',
            'historico' => $message,
            'status_anterior' => $charge->status->value,
            'status_novo' => $charge->status->value,
            'created_at' => now(),
            'created_by' => $userId,
        ]);
    }

    private function register(FinCharges $charge, ?int $userId, ?string $due): FinChargesBoletoSicredi
    {
        $payload = ($this->payload)($charge, $due);
        $response = $this->api->register($payload);
        $body = is_array($response['body']) ? $response['body'] : [];
        $line = preg_replace('/\D+/', '', (string) ($body['linhaDigitavel'] ?? ''));
        $error = match (true) {
            ! $response['ok'] => SicrediApi::errorMessage($response, 'Falha ao cadastrar boleto na Sicredi.'),
            $line === '' => 'Sicredi não retornou linha digitável.',
            default => null,
        };

        $boleto = FinChargesBoletoSicredi::forceCreate([
            'ref_charge' => $charge->id,
            'seu_numero' => $payload['seuNumero'],
            'nosso_numero' => isset($body['nossoNumero']) ? (string) $body['nossoNumero'] : null,
            'linha_digitavel' => $line ?: null,
            'codigo_barras' => isset($body['codigoBarras']) ? (string) $body['codigoBarras'] : null,
            'valor' => $payload['valor'],
            'data_vencimento' => $payload['dataVencimento'],
            // "erro" até o PDF chegar
            'status' => 'erro',
            'response_json' => json_encode(['request' => $payload, 'response' => $body ?: ['raw' => $response['raw']], 'http_code' => $response['http_code']]),
            'erro_mensagem' => $error,
            'created_by' => $userId,
        ]);

        return $error ? throw new DomainException($error) : $boleto;
    }

    /**
     * baixarPdfEAtualizar: baixa o PDF do boleto já cadastrado e o marca como emitido; sem o PDF, fica como "erro".
     *
     * @throws DomainException
     */
    public function attachPdf(FinCharges $charge, FinChargesBoletoSicredi $boleto): FinChargesBoletoSicredi
    {
        $response = $this->downloadPdf($boleto->linha_digitavel);

        if (! self::isPdf($response)) {
            $detail = SicrediApi::errorMessage($response, 'Boleto cadastrado, mas falha ao baixar o PDF.');
            $error = "Boleto cadastrado na Sicredi (linha digitável disponível), mas o PDF não foi liberado. Tente gerar novamente em alguns segundos. Detalhe: {$detail}";
            $boleto->update(['status' => 'erro', 'erro_mensagem' => $error]);
            logger()->error("[SicrediPdf] falha charge={$charge->id} nosso={$boleto->nosso_numero} http={$response['http_code']} msg={$detail}");

            throw new DomainException($error);
        }

        $name = 'boleto_sicredi_'.$charge->id.'_'.now()->format('YmdHis');
        Storage::disk('tracking_uploads')->put("boleto/{$name}.pdf", $response['raw']);
        $file = CmnFile::forceCreate(['name' => $name, 'subject' => "Boleto Sicredi cobrança #{$charge->id}", 'created_at' => now()->toDateString()]);
        $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

        $boleto->update(['ref_file' => $file->getKey(), 'status' => 'emitido', 'erro_mensagem' => null]);

        return $boleto;
    }

    /**
     * baixarPdfComRetentativa: a Sicredi devolve a linha digitável no cadastro, mas o PDF costuma responder
     * "Boleto não localizado" por alguns segundos. Repete só nesse caso, no limite de requisições ou sem resposta.
     */
    private function downloadPdf(string $digitableLine): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->api->pdf($digitableLine);

            if (self::isPdf($response) || $attempt === self::PDF_ATTEMPTS || ! (in_array($response['http_code'], [0, 429], true)
                || str_contains(strtolower(Str::ascii(SicrediApi::errorMessage($response, ''))), 'nao localizado'))) {
                return $response;
            }

            Sleep::for(2)->seconds();
        }
    }

    private static function isPdf(array $response): bool
    {
        return $response['ok'] && str_starts_with($response['raw'], '%PDF');
    }
}

<?php

namespace App\Actions\Cobranca\Boleto;

use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\Cobranca\Settlement\BoletoSettlement;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesBoletoSicredi;
use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use App\Models\FinSicrediWebhookEvento;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * SicrediWebhookService::processar (legado): a liquidação marca o boleto e dá baixa na IC aberta (declaração e NFS-e,
 * como na baixa por boleto); o estorno só fica registrado. Cada idEventoWebhook é processado uma vez.
 *
 * Diferente do legado: o evento, o boleto e a baixa são gravados na mesma transação, com a cobrança travada.
 *
 * @phpstan-type Outcome array{ok:bool, duplicado:bool, outcome:string, id_evento:string, evento_id:int, ref_charge:?int, mensagem:string}
 */
class ReceiveSicrediWebhook
{
    private const SETTLEMENTS = ['LIQUIDACAO_PIX', 'LIQUIDACAO_REDE', 'LIQUIDACAO_COMPE_H5', 'LIQUIDACAO_COMPE_H6', 'LIQUIDACAO_COMPE_H8', 'LIQUIDACAO_CARTORIO'];

    private const REVERSAL = 'ESTORNO_LIQUIDACAO_REDE';

    private const SYSTEM_USER = 1;

    public function __construct(
        private BoletoSettlement $settlement,
        private IssueNfse $issue,
    ) {}

    /** @return Outcome */
    public function __invoke(array $payload, string $raw): array
    {
        $eventId = trim((string) ($payload['idEventoWebhook'] ?? ''));
        $ourNumber = preg_replace('/\D+/', '', (string) ($payload['nossoNumero'] ?? ''));
        $movement = trim((string) ($payload['movimento'] ?? ''));

        if ($eventId === '' || $ourNumber === '' || $movement === '') {
            throw new InvalidArgumentException('Payload incompleto: idEventoWebhook, nossoNumero e movimento são obrigatórios.');
        }

        try {
            [$outcome, $paid] = DB::transaction(fn () => $this->record($eventId, $ourNumber, $movement, $payload, $raw));
        } catch (UniqueConstraintViolationException $e) {
            // o mesmo evento chegou duas vezes ao mesmo tempo
            $event = FinSicrediWebhookEvento::where('id_evento_webhook', $eventId)->first() ?? throw $e;

            return self::duplicate($event);
        }

        if ($paid) {
            $this->afterSettlement(...$paid);
        }

        return $outcome;
    }

    /** @return array{0:Outcome, 1:?array{0:FinCharges, 1:string}} o resultado e, quando houve baixa, a cobrança e a declaração */
    private function record(string $eventId, string $ourNumber, string $movement, array $payload, string $raw): array
    {
        if ($event = FinSicrediWebhookEvento::where('id_evento_webhook', $eventId)->first()) {
            return [self::duplicate($event), null];
        }

        $boleto = FinChargesBoletoSicredi::where('nosso_numero', $ourNumber)->orderByDesc('id')->first();
        $charge = $boleto ? FinCharges::lockForUpdate()->find($boleto->ref_charge) : null;
        $declaration = null;

        [$outcome, $status, $message] = match (true) {
            ! $boleto => ['not_found', 'ignorado', "Boleto Sicredi não encontrado para nossoNumero={$ourNumber}"],
            $movement === self::REVERSAL => ['estornado', 'processado', 'Estorno registrado; cobrança não foi reaberta.'],
            in_array($movement, self::SETTLEMENTS, true) => ['liquidado', 'processado', $charge && $charge->status !== ChargeStatus::Open
                ? "Boleto liquidado; cobrança já estava com status={$charge->status->value}."
                : 'Boleto marcado como liquidado.'],
            default => ['ignored', 'ignorado', "Movimento não tratado: {$movement}"],
        };

        if ($outcome === 'estornado') {
            $boleto->update(['status' => 'estornado']);

            if ($charge) {
                IssueBoleto::log($charge, "Webhook Sicredi: ESTORNO_LIQUIDACAO_REDE para nossoNumero={$boleto->nosso_numero}. Baixa da IC não foi revertida automaticamente.", self::SYSTEM_USER);
            }
        }

        if ($outcome === 'liquidado') {
            $boleto->update(['status' => 'liquidado', 'erro_mensagem' => null]);

            if ($charge?->status === ChargeStatus::Open) {
                $declaration = $this->settle($charge, $payload);
                [$outcome, $message] = ['baixado', 'Liquidação processada e cobrança baixada por boleto.'];
            }
        }

        $event = FinSicrediWebhookEvento::forceCreate([
            'id_evento_webhook' => $eventId,
            'nosso_numero' => $ourNumber,
            'movimento' => $movement,
            'valor_liquidacao' => self::number($payload['valorLiquidacao'] ?? null),
            'payload_json' => $raw,
            'ref_boleto_sicredi' => $boleto?->id,
            'ref_charge' => $boleto?->ref_charge,
            'status_processamento' => $status,
            'mensagem' => $message,
            'created_at' => now(),
        ]);

        return [
            ['ok' => true, 'duplicado' => false, 'outcome' => $outcome, 'id_evento' => $eventId, 'evento_id' => (int) $event->getKey(), 'ref_charge' => $charge?->id, 'mensagem' => $message],
            $declaration ? [$charge, $declaration] : null,
        ];
    }

    /** baixarCobrancaPorBoleto. @return string nome do arquivo da declaração */
    private function settle(FinCharges $charge, array $payload): string
    {
        $date = self::date($payload['dataEvento'] ?? null);
        $received = self::number($payload['valorLiquidacao'] ?? null);
        $received = $received > 0 ? $received : (float) $charge->total_value - (float) $charge->discount;
        $discount = self::number($payload['valorDesconto'] ?? null);

        $charge->forceFill(array_filter([
            'interest' => self::number($payload['valorJuros'] ?? null),
            'fine' => self::number($payload['valorMulta'] ?? null),
        ], fn ($v) => $v !== null) + ($discount > 0 ? ['discount' => $discount, 'discount_status' => 1] : []) + [
            'received_value' => $received,
            'payment_date' => $date,
            'payment_confirmation' => $date,
            'status' => ChargeStatus::Boleto,
            'updated_by' => self::SYSTEM_USER,
            'updated_at' => now()->toDateString(),
        ])->save();

        FinChargesLog::forceCreate([
            'ref_charge' => $charge->id,
            'acao' => 'baixa_boleto',
            'historico' => "Baixa por boleto via webhook Sicredi. Valor recebido: {$received}; Movimento: {$payload['movimento']}; Nosso número: {$payload['nossoNumero']}",
            'status_anterior' => ChargeStatus::Open->value,
            'status_novo' => ChargeStatus::Boleto->value,
            'created_at' => now(),
            'created_by' => self::SYSTEM_USER,
        ]);

        return $this->settlement->declare($charge, $date)->file->name;
    }

    /** Depois do commit: envia a declaração e emite a NFS-e. A baixa já está feita, então a falha só é registrada. */
    private function afterSettlement(FinCharges $charge, string $declaration): void
    {
        try {
            $this->settlement->sendDeclaration($charge, $declaration);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            // emitirNaGeracaoCobranca: só para quem emite com a IC e ainda não tem nota
            $booking = $charge->embarque;
            $latest = FinChargesNfse::where('ref_charge', $charge->id)->orderByDesc('fin_charges_nfse_id')->value('status');

            if ($latest !== 'autorizada'
                && in_array($booking->impor_expor, ['i', 'e'], true)
                && ! ServiceTaker::blockedByRule($booking)
                && ! IssueNfse::clientInException($charge)
                && EmissionGroups::issuedWithInstruction($booking)
                && ! FinChargesNfse::hasActiveEmission($charge->id)) {
                $this->issue->withInstruction($charge, self::SYSTEM_USER, sendEmail: true);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Outcome */
    private static function duplicate(FinSicrediWebhookEvento $event): array
    {
        return ['ok' => true, 'duplicado' => true, 'outcome' => 'duplicado', 'id_evento' => $event->id_evento_webhook, 'evento_id' => (int) $event->getKey(), 'ref_charge' => $event->ref_charge ? (int) $event->ref_charge : null, 'mensagem' => 'Evento já processado.'];
    }

    /** dataEvento vem como [ano, mês, dia, ...] ou texto; sem ela, hoje. */
    private static function date(mixed $value): string
    {
        if (is_array($value) && count($value) >= 3 && (int) $value[0] > 0 && (int) $value[1] > 0 && (int) $value[2] > 0) {
            return sprintf('%04d-%02d-%02d', $value[0], $value[1], $value[2]);
        }

        if (is_string($value) && strtotime($value) !== false) {
            return Carbon::parse($value)->toDateString();
        }

        return now()->toDateString();
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) str_replace(',', '.', (string) $value);
    }
}

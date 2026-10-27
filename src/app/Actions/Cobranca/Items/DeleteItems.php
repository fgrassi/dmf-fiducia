<?php

namespace App\Actions\Cobranca\Items;

use App\Enums\Cobranca\ChargeStatus;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\User;
use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * excluirItensIc (legado, Cobranca::excluirItensCobranca): remove despesas de uma cobrança em aberto,
 * recalcula IOF e total, registra no histórico e avisa o financeiro por e-mail.
 */
class DeleteItems
{
    /** Cobranca::getRecipientList('financial'): o legado usa só o e-mail do grupo. */
    private const FINANCE_EMAIL = 'financeiro@dmflog.com.br';

    private const MIN_JUSTIFICATION = 15;

    public function __construct(private ChargeIof $iof) {}

    /** NFS-e enfileirada ou processando bloqueia a exclusão (FinCharges::temNfseEmProcessamento). */
    public static function hasNfseInProgress(FinCharges $charge): bool
    {
        return $charge->nfses()->whereIn('status', ['enfileirada', 'processando'])->exists();
    }

    /** FinCharges::temNfseEmitida: existe NFS-e autorizada. */
    public static function hasIssuedNfse(FinCharges $charge): bool
    {
        return $charge->nfses()->where('status', 'autorizada')->exists();
    }

    /**
     * @param  list<int|string>  $itemIds
     * @return string mensagem de sucesso
     */
    public function __invoke(FinCharges $charge, array $itemIds, string $justification, ?User $user): string
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Somente cobranças em aberto permitem exclusão de despesas.');
        }

        if (self::hasNfseInProgress($charge)) {
            throw new DomainException('Não é possível excluir despesas enquanto houver NFS-e em processamento.');
        }

        $justification = trim($justification);

        if (mb_strlen($justification) < self::MIN_JUSTIFICATION) {
            throw new DomainException('A justificativa deve ter no mínimo '.self::MIN_JUSTIFICATION.' caracteres.');
        }

        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));

        if ($itemIds === []) {
            throw new DomainException('Selecione ao menos uma despesa para excluir.');
        }

        $labels = collect(app(ChargeDetails::class)->items($charge))->pluck('expense', 'id');
        $items = $charge->items()->get()->keyBy('id');

        if ($items->count() <= 1) {
            throw new DomainException('A cobrança precisa ter mais de uma despesa para permitir exclusão parcial.');
        }

        if (count($itemIds) >= $items->count()) {
            throw new DomainException('Não é permitido excluir todas as despesas da cobrança.');
        }

        $removed = collect($itemIds)->map(function (int $id) use ($items, $labels) {
            if (! $items->has($id)) {
                throw new DomainException("Despesa #{$id} não pertence a esta cobrança.");
            }

            return ['label' => $labels[$id] ?? "Item #{$id}", 'value' => round((float) $items[$id]->item_value, 2)];
        });

        $previousTotal = (float) $charge->total_value;

        $history = DB::transaction(function () use ($charge, $itemIds, $removed, $previousTotal, $justification, $user) {
            $charge->items()->whereKey($itemIds)->delete();

            $iof = $this->iof->amount($charge);
            $charge->forceFill([
                'valor_iof' => $iof,
                'total_value' => (float) $charge->items()->sum('item_value') + $iof,
                'updated_at' => now()->toDateString(),
            ])->save();

            $charge->embarque->forceFill(['ctr_desp' => 1])->save();

            $history = 'Despesas excluídas: '.$removed->map(fn ($r) => "{$r['label']} (R$ ".self::brl($r['value']).')')->implode('; ')
                .'. Valor removido: R$ '.self::brl($removed->sum('value'))
                .'. Total da IC: R$ '.self::brl($previousTotal).' → R$ '.self::brl($charge->total_value)
                .'. Justificativa: '.$justification;

            FinChargesLog::forceCreate([
                'ref_charge' => $charge->id,
                'acao' => 'exclusao_despesa',
                'historico' => $history,
                'status_anterior' => 0,
                'status_novo' => 0,
                'created_at' => now(),
                'created_by' => $user?->getKey(),
            ]);

            return $history;
        });

        $issuedNfse = self::hasIssuedNfse($charge);
        $booking = (string) $charge->embarque->booking;

        Mail::send('mail.cobranca.items-deleted', [
            'charge' => $charge,
            'booking' => $booking,
            'user' => $user?->getFilamentName() ?: 'Sistema',
            'justification' => $justification,
            'removed' => $removed,
            'previousTotal' => self::brl($previousTotal),
            'total' => self::brl($charge->total_value),
            'iof' => self::brl($charge->valor_iof),
            'history' => $history,
            'issuedNfse' => $issuedNfse,
        ], fn ($mail) => $mail
            ->to(self::FINANCE_EMAIL)
            ->subject("DMF Logistics: Exclusão de despesas na IC #{$charge->id}".($booking !== '' ? " — {$booking}" : '')));

        return 'Despesa(s) excluída(s) com sucesso.'
            .($issuedNfse ? ' Recalcule a cobrança e atualize a NFS-e com o valor atual.' : '');
    }

    private static function brl(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}

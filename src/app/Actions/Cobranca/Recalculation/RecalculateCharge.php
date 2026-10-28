<?php

namespace App\Actions\Cobranca\Recalculation;

use App\Actions\Cobranca\Items\ChargeIof;
use App\Actions\Cobranca\Items\DeleteItems;
use App\Actions\Cobranca\Items\ItemPricing;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\CmnFile;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use App\Models\User;
use App\Services\Cobranca\ChargeDetails;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * recalculaCobranca (legado): recalcula valor unitário, cotação e valor de cada item, IOF e total.
 * Modos: só esta IC, todas as ICs abertas da reserva (todos=s) ou unindo as abertas do mesmo BL na mais antiga (unir=s).
 * Depois do commit, cada IC recalculada passa pelo AfterRecalculation (substituição da NFS-e, PDF e e-mail da IC).
 *
 * @phpstan-type NfseOptions array{reason?:string, justification?:string, emails?:?list<string>}
 */
class RecalculateCharge
{
    public const SINGLE = 'single';

    public const ALL = 'all';

    public const MERGE = 'merge';

    /** Log extra do recálculo com NFS-e autorizada (motivo/justificativa da substituição e e-mails escolhidos). */
    private string $logExtra = '';

    public function __construct(
        private ChargeRates $rates,
        private ChargeIof $iof,
        private ChargeDetails $factors,
        private AfterRecalculation $after,
        private ItemPricing $pricing,
    ) {}

    /** Botão "Todas" só aparece com mais de uma IC na reserva. */
    public static function bookingHasOtherCharges(FinCharges $charge): bool
    {
        return FinCharges::where('ref_booking', $charge->ref_booking)->count() > 1;
    }

    /**
     * @param  bool  $keepIof  "Manter IOF atual ao recalcular" (só no modo SINGLE)
     * @param  NfseOptions  $nfse  motivo da substituição e e-mails do cliente (modal do recálculo com NFS-e autorizada)
     * @return FinCharges a IC para onde a tela deve ir (no MERGE, a mais antiga)
     */
    public function __invoke(FinCharges $charge, string $mode, bool $withoutIof, bool $keepIof, User $user, array $nfse = []): FinCharges
    {
        if ($charge->status !== ChargeStatus::Open) {
            throw new DomainException('Não é possível recalcular cobranças pagas.');
        }

        $charges = $this->involved($charge, $mode);

        if ($charges->contains(fn (FinCharges $c) => DeleteItems::hasNfseInProgress($c))) {
            throw new DomainException('Não é possível recalcular enquanto houver NFS-e em processamento. Aguarde a finalização e tente novamente.');
        }

        $reason = FinChargesNfse::substitutionReason($nfse['reason'] ?? '');
        $justification = trim((string) ($nfse['justification'] ?? ''));
        $this->logExtra = $reason !== ''
            ? 'Motivo subst. NFS-e: '.FinChargesNfse::SUBSTITUTION_REASONS[$reason].($justification !== '' ? "; justificativa: {$justification}" : '')
            : '';

        // aplicarEmailsDestinoNasCobrancasRelacionadas: a seleção vale para todas as ICs do recálculo
        if (array_key_exists('emails', $nfse) && $nfse['emails'] !== null) {
            $charges->each(function (FinCharges $c) use ($nfse) {
                $c->selectRecipients($nfse['emails']);
                $c->save();
            });
            $this->logExtra = trim($this->logExtra.FinCharges::recipientsLogSummary($nfse['emails']));
        }

        $done = [];
        $target = DB::transaction(function () use ($charge, $charges, $mode, $withoutIof, $keepIof, $user, &$done) {
            if ($mode === self::MERGE) {
                $charge = $this->merge($charges);

                return $done[] = $this->recalculate($charge, $withoutIof, false, $user, $this->factors->allowsRecalculation($charge), true);
            }

            if ($mode === self::ALL) {
                $negotiated = $charge->customRates()->get();

                foreach ($charges as $c) {
                    if ($c->isNot($charge)) {
                        $negotiated->each(fn ($rate) => $rate->replicate()->forceFill(['ref_charge' => $c->id, 'created_at' => $rate->getRawOriginal('created_at')])->save());
                    }

                    // legado: no "Todas" recalcula sempre, e IC com cotação desatualizada é pulada sem erro
                    $done[] = $this->recalculate($c, $withoutIof, false, $user, true, false, failOnRates: false);
                }

                return $charge;
            }

            return $done[] = $this->recalculate($charge, $withoutIof, $keepIof, $user, $this->factors->allowsRecalculation($charge), false);
        });

        // depois do commit, como o legado: PDF, e-mail e NFS-e não voltam atrás
        foreach ($done as $c) {
            ($this->after)($c->refresh(), $user->getKey(), $reason ?: '99', $justification);
        }

        return $target;
    }

    /** @return Collection<int, FinCharges> */
    private function involved(FinCharges $charge, string $mode): Collection
    {
        $open = FinCharges::where('ref_booking', $charge->ref_booking)->where('status', ChargeStatus::Open)->orderBy('id');

        return match ($mode) {
            self::SINGLE => new Collection([$charge]),
            self::ALL => $open->get(),
            self::MERGE => $open->when($charge->ref_bl,
                fn ($q, $bl) => $q->where('ref_bl', $bl),
                fn ($q) => $q->whereNull('ref_bl'))->get(),
            default => throw new DomainException("Modo de recálculo inválido: {$mode}"),
        };
    }

    /** unir=s: itens e cotações negociadas vão para a IC mais antiga; as demais (e seus documentos) são apagadas. */
    private function merge(Collection $charges): FinCharges
    {
        $target = $charges->first();

        foreach ($charges->slice(1) as $c) {
            $c->items()->update(['ref_charge' => $target->id]);
            $c->customRates()->update(['ref_charge' => $target->id]);

            $files = $c->files()->pluck('ref_file');
            $c->files()->delete();
            CmnFile::whereKey($files)->delete();

            $c->delete();
        }

        return $target;
    }

    /**
     * @param  bool  $recalculateItems  FinCharges::recalcular() (armador permite) — sem ele só o IOF/total é refeito
     * @param  bool  $merging  no "unir" sem recálculo de itens o legado ainda refaz IOF e total
     */
    private function recalculate(FinCharges $charge, bool $withoutIof, bool $keepIof, User $user, bool $recalculateItems, bool $merging, bool $failOnRates = true): FinCharges
    {
        $charge->forceFill([
            'updated_at' => now()->toDateString(),
            'due_date' => $charge->due_date ?? now()->toDateString(),
        ])->save();

        $embarque = $charge->embarque;

        DB::table('transborda')->insert([
            'usuario_id' => 1,
            'embarque_id' => $embarque->embarque_id,
            'data_reg' => now()->toDateString(),
            'descr' => $embarque->impor_expor === 'i' ? 'Payment request resent to Cnee' : 'Payment request resent to Shipper',
            'ref_frase_transborda' => 59,
        ]);

        if (! $recalculateItems) {
            // legado: só esta IC sem recálculo do armador mexe no IOF apenas no "SEM IOF"
            if ($merging || $withoutIof) {
                $this->saveTotals($charge, $withoutIof, false);
            }

            return $charge;
        }

        if (! $this->rates->upToDate($charge)) {
            if ($failOnRates) {
                throw new DomainException('Não foi possível atualizar a cobrança. Taxas desatualizadas.');
            }

            return $charge;
        }

        $this->recalculateItems($charge);
        $this->saveTotals($charge, $withoutIof, $keepIof);

        FinChargesLog::forceCreate([
            'ref_charge' => $charge->id,
            'acao' => 'recalculo',
            'historico' => "Cobrança recalculada. Total: {$charge->total_value}; IOF: {$charge->valor_iof}".($this->logExtra !== '' ? "; {$this->logExtra}" : ''),
            'status_anterior' => $charge->status->value,
            'status_novo' => $charge->status->value,
            'created_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        DB::table('rastreamento')->insert([
            'assunto' => 'Cobranca Recalculada',
            'evento' => 'u',
            'historico' => "A cobranca referente ao embarque {$embarque->booking} foi recalculada pelo usuário {$user->getFilamentName()} em ".now()->format('d/m/Y'),
            'ref_embarque' => $embarque->embarque_id,
            'created_at' => now()->toDateString(),
            'created_time' => now()->format('H:i:s'),
            'created_by' => $user->getKey(),
        ]);

        return $charge;
    }

    private function saveTotals(FinCharges $charge, bool $withoutIof, bool $keepIof): void
    {
        $iof = match (true) {
            $withoutIof => 0.0,
            ! $this->iof->applies($charge) => 0.0,
            $keepIof => (float) $charge->valor_iof,
            default => $this->iof->calculated($charge),
        };

        $charge->forceFill([
            'valor_iof' => $iof,
            'total_value' => (float) $charge->items()->sum('item_value') + $iof,
        ])->save();
    }

    /** Recalcula valor unitário, cotação e valor de cada item (compartilhado com o recálculo por serviço). */
    public function recalculateItems(FinCharges $charge): void
    {
        $embarque = $charge->embarque;
        $clientId = ChargeRates::clientId($embarque);
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->first() : null;

        foreach ($charge->items()->with('expense.despesa')->get() as $item) {
            $expense = $item->expense;
            $rate = $this->rates->rate($clientId, $expense->ref_currency_sr, $charge);
            $unit = $this->pricing->unitValue($expense);
            $value = $this->pricing->debit($expense, $rate, $unit, $bl);

            if (in_array((int) $expense->ref_expense, ItemPricing::DISCOUNT_EXPENSES, true)) {
                $unit = -abs($unit);
                $value = $value === null ? null : -abs($value);
            }

            $item->forceFill([
                'unitary_value' => $unit,
                'ref_currency' => $expense->ref_currency_sr,
                'current_rate_value' => $rate,
                'item_value' => $value,
            ])->save();
        }
    }
}

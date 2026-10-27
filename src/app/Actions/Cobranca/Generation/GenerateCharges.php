<?php

namespace App\Actions\Cobranca\Generation;

use App\Actions\Cobranca\Documents\InstructionPdf;
use App\Actions\Cobranca\Documents\InsurancePdf;
use App\Actions\Cobranca\Items\ChargeIof;
use App\Actions\Cobranca\Items\ItemPricing;
use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Actions\Cobranca\Nfse\EmissionGroups;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\NfseAlert;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Enums\Cobranca\ChargeStatus;
use App\Models\Embarque;
use App\Models\FinCharges;
use App\Models\FinChargesLog;
use App\Models\FinChargesNfse;
use App\Models\FinExpensesValues;
use App\Services\Cobranca\ChargeDetails;
use Closure;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Cobranca::geraCobranca (legado): gera as Instruções de Cobrança das reservas com despesas fora de IC.
 * Por reserva: IC de frete separada (cliente com separa_taxas), checagens (despesas, cotações, liberação), uma IC por BL
 * (ou uma só nos modais sem BL), fatiada por regra de despesa do cliente; cada IC gera o PDF, vai ao cliente e emite a NFS-e
 * quando o cliente tem prazo/boleto. No fim, o financeiro recebe o resumo, os alertas de NFS-e e o digest de invoice.
 *
 * Handling (geraCobrancaHandling) é uma variante: uma IC por BL, sem liberação, invoice, tomador, split nem NFS-e.
 */
class GenerateCharges
{
    public const TYPE_NORMAL = 1;

    public const TYPE_EXTRA = 2;

    /** Como a IC é montada: geraCobranca, geraFrete (frete separado) ou geraCobrancaHandling. */
    private const NORMAL = 'normal';

    private const FREIGHT = 'freight';

    private const HANDLING = 'handling';

    /** verificaBL = 0 nestes modais gera uma IC sem BL. */
    private const WITHOUT_BL = ['s', 'a', 'd', 'r'];

    /** @var list<string> linhas do resumo enviado ao financeiro */
    private array $created = [];

    /** @var list<array> */
    private array $nfseAlerts = [];

    private ?int $userId = null;

    public function __construct(
        private BookingChecks $checks,
        private ChargeRates $rates,
        private ItemPricing $pricing,
        private ChargeIof $iof,
        private ChargeDueDate $dueDate,
        private ChargeDetails $details,
        private InstructionPdf $pdf,
        private InsurancePdf $insurancePdf,
        private IssueNfse $issue,
        private ServiceTaker $taker,
        private NfseAlert $alert,
        private InvoiceGate $invoices,
    ) {}

    /**
     * @param  iterable<array{0:int, 1:Closure}>  $sources  [embarque_id, marca a origem como gerada (control = 1)]
     * @param  ?list<int>  $only  despesas (fin_expenses_values) escolhidas; null = todas as pendentes
     * @return int ICs criadas
     */
    public function __invoke(iterable $sources, ?array $only, ?int $userId): int
    {
        $this->start($userId);

        foreach ($sources as [$embarqueId, $markGenerated]) {
            $b = Embarque::find($embarqueId);

            if ($b && $this->booking($b, $only)) {
                $markGenerated();
            }
        }

        return $this->finish();
    }

    /**
     * geraCobrancaHandling: reservas de handling pendentes (ctr_desp = 1), uma IC por BL.
     *
     * @param  iterable<Embarque>  $bookings
     * @return int ICs criadas
     */
    public function handling(iterable $bookings, ?int $userId): int
    {
        $this->start($userId);

        foreach ($bookings as $b) {
            if (! $this->checks->hasPending($b) || ! $this->checks->expensesConsistent($b)) {
                if (! $this->checks->hasPending($b)) {
                    $b->forceFill(['ctr_desp' => 0])->save();
                }

                continue;
            }

            if (! $this->checks->ratesUpToDate($b, null)) {
                continue;
            }

            $expenses = $this->expenses($b, $this->checks->pendingIds($b, null), BookingChecks::billing($b));

            foreach (DB::table('bl')->where('embarque_id', $b->embarque_id)->orderBy('bl_id')->get() as $bl) {
                $this->create($b, $bl, ChargeRates::clientId($b), $expenses, mode: self::HANDLING);
            }
        }

        // handling não tem NFS-e nem controle de invoice: só o resumo
        $this->summary();

        return count($this->created);
    }

    private function start(?int $userId): void
    {
        $this->created = [];
        $this->nfseAlerts = [];
        $this->userId = $userId;
    }

    /** Fim da rodada: resumo, alertas de NFS-e e digest de invoice ao financeiro. @return int ICs criadas */
    private function finish(): int
    {
        $this->summary();
        ($this->alert)($this->nfseAlerts);
        $this->invoices->sendDigest();

        return count($this->created);
    }

    /** @return bool se a origem deve ser marcada como gerada */
    private function booking(Embarque $b, ?array $only): bool
    {
        if ($b->tipo_embarque === 'm' && (int) DB::table('pessoa')->where('pessoa_id', $b->cliente_id)->value('separa_taxas') === 1) {
            $this->freight($b);
        }

        if (! $this->checks->hasPending($b) || ! $this->checks->expensesConsistent($b)) {
            if (! $this->checks->hasPending($b)) {
                $b->forceFill(['ctr_desp' => 0])->save();
            }

            return false;
        }

        if (! $this->checks->ratesUpToDate($b, $only) || ! $this->checks->released($b)) {
            return false;
        }

        $clientId = ChargeRates::clientId($b);
        $ids = $this->checks->pendingIds($b, $only);

        if (in_array($b->tipo_embarque, self::WITHOUT_BL, true) && ! DB::table('bl')->where('embarque_id', $b->embarque_id)->whereNotNull('bl')->exists()) {
            return ! $this->takerBlocks($b, null) && $this->forContext($b, null, $clientId, $ids) > 0;
        }

        $blocked = false;
        $generated = false;

        foreach (DB::table('bl')->where('embarque_id', $b->embarque_id)->orderBy('bl_id')->get() as $bl) {
            if ($this->invoices->blocks($b, $bl, $this->chargeType($b, $bl), $this->userId)) {
                $blocked = true;

                continue;
            }

            if (! $this->takerBlocks($b, $bl) && $this->forContext($b, $bl, $clientId, $ids) > 0 && ! $blocked) {
                $generated = true;
            }
        }

        return $generated;
    }

    /** gerarCobrancasParaContexto: uma IC, ou uma por regra de despesa do cliente (+ "Demais despesas"). */
    private function forContext(Embarque $b, ?stdClass $bl, int $clientId, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $expenses = $this->expenses($b, $ids, BookingChecks::billing($b));
        $groups = $this->ruleGroups($b, $expenses, $clientId);

        if ($groups === []) {
            return (int) $this->create($b, $bl, $clientId, $expenses);
        }

        return collect($groups)->sum(fn (array $g) => (int) $this->create($b, $bl, $clientId, $g['expenses'], $g['rule'] ?? 'Demais despesas'));
    }

    /**
     * montarGruposIcPorRegraDespesa: cliente com "separar NF por grupo de despesas" tem uma IC por regra do modal;
     * o que sobra vai para "Demais despesas". Sem fatiar (lista vazia) quando só um grupo teria itens.
     *
     * @param  Collection<int, FinExpensesValues>  $expenses
     * @return list<array{rule:?string, expenses:Collection<int, FinExpensesValues>}>
     */
    private function ruleGroups(Embarque $b, Collection $expenses, int $clientId): array
    {
        $rule = DB::table('fin_clients_nfse_regra')->where('ref_client', $clientId)->first();

        if (! $rule || ! $rule->separar_nf_grupo_despesas) {
            return [];
        }

        // a primeira regra (por id) de cada despesa vale
        $ruleOf = DB::table('regra_despesa_cobranca as r')
            ->join('regra_despesa_cobranca_item as ri', 'ri.regra_despesa_cobranca_id', '=', 'r.regra_despesa_cobranca_id')
            ->join('fin_clients_nfse_regra_despesa as cr', 'cr.regra_despesa_cobranca_id', '=', 'r.regra_despesa_cobranca_id')
            ->where('cr.ref_nfse_regra', $rule->id)
            ->where('r.tipo_embarque', $b->tipo_embarque)
            ->where('r.impor_expor', $b->impor_expor)
            ->orderBy('r.regra_despesa_cobranca_id')
            ->get(['ri.despesa_id', 'r.regra_despesa_cobranca_id', 'r.nome'])
            ->unique('despesa_id')
            ->keyBy('despesa_id');

        $grouped = $expenses->groupBy(fn ($e) => $ruleOf[$e->ref_expense]->regra_despesa_cobranca_id ?? 0);

        if ($grouped->count() <= 1 && $grouped->has(0)) {
            return [];
        }

        $groups = $grouped->except(0)
            ->map(fn (Collection $items) => ['rule' => $ruleOf[$items->first()->ref_expense]->nome, 'expenses' => $items])
            ->values();

        if ($grouped->has(0)) {
            $groups->push(['rule' => null, 'expenses' => $grouped[0]]);
        }

        return $groups->count() > 1 ? $groups->all() : [];
    }

    /**
     * geraFrete: cliente com separa_taxas tem antes uma IC só do frete (regra 4) por BL, sem NFS-e.
     * ponytail: como no legado, só EXPO gera (em IMPO a lista de despesas ficava vazia e a IC era descartada).
     */
    private function freight(Embarque $b): void
    {
        if ($b->impor_expor !== 'e'
            || ! $this->checks->hasPendingFreight($b)
            || ! $this->checks->expensesConsistent($b)
            || ! $this->checks->ratesUpToDate($b, null)
            || ! $this->checks->released($b)) {
            return;
        }

        $expenses = $this->expenses($b, $this->checks->freightIds($b), 'p');

        foreach (DB::table('bl')->where('embarque_id', $b->embarque_id)->orderBy('bl_id')->get() as $bl) {
            $this->create($b, $bl, ChargeRates::clientId($b), $expenses, mode: self::FREIGHT);
        }
    }

    /** buscaDespesas: as despesas escolhidas da reserva, do lado cobrado, por nome. @return Collection<int, FinExpensesValues> */
    private function expenses(Embarque $b, array $ids, ?string $billing): Collection
    {
        return FinExpensesValues::with('despesa')
            ->select('fin_expenses_values.*')
            ->join('fin_expenses as fe', 'fe.id', '=', 'fin_expenses_values.ref_expense')
            ->where('fin_expenses_values.ref_embarque', $b->embarque_id)
            ->whereIn('fin_expenses_values.id', $ids)
            ->when($billing, fn ($q, $v) => $q->where('fin_expenses_values.billing', $v))
            ->orderBy('fe.name')
            ->get();
    }

    /**
     * gerarCobrancaComDespesas: cria a IC com os itens, IOF e total; descarta se ficar zerada.
     * Depois do commit (como o legado, PDF/e-mail/NFS-e não voltam atrás): PDF da IC e NFS-e.
     *
     * @param  Collection<int, FinExpensesValues>  $expenses
     * @param  ?string  $split  nome do grupo quando a IC foi fatiada por regra de despesa
     */
    private function create(Embarque $b, ?stdClass $bl, int $clientId, Collection $expenses, ?string $split = null, string $mode = self::NORMAL): bool
    {
        DB::beginTransaction();

        try {
            $charge = (new FinCharges)->forceFill([
                'created_at' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'ref_booking' => $b->embarque_id,
                'ref_bl' => $bl?->bl_id,
                'ref_type' => $this->chargeType($b, $bl),
                'status' => ChargeStatus::Open,
            ])->setRelation('embarque', $b);
            $charge->save();
            $charge->forceFill(['due_date' => $this->dueDate->final($charge)])->save();

            $this->log($charge, 'criacao', "IC criada. Booking: {$b->booking}".($bl ? "; BL id: {$bl->bl_id}" : '')."; Tipo: {$charge->ref_type}");

            $zeroed = $this->items($charge, $b, $bl, $clientId, $expenses, $mode);
            $sum = (float) $charge->items()->sum('item_value');

            if ($sum <= 0 || $zeroed) {
                DB::rollBack();

                return false;
            }

            $iof = $this->iof->calculated($charge);
            $charge->forceFill(['valor_iof' => $iof, 'total_value' => $sum + $iof])->save();

            if (! $this->checks->hasPending($b)) {
                $b->forceFill(['ctr_desp' => 0])->save();
            }

            DB::table('transborda')->insert([
                'usuario_id' => 1,
                'embarque_id' => $b->embarque_id,
                'data_reg' => now()->toDateString(),
                'descr' => $b->impor_expor === 'i' ? 'Payment request sent to Cnee' : 'Payment request sent to Shipper',
                'ref_frase_transborda' => 59,
            ]);

            if ($split !== null) {
                $this->log($charge, 'alteracao', "IC gerada por split de regra de despesa: {$split}");
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($b->tipo_embarque === 's') {
            ($this->insurancePdf)($charge);
        } else {
            ($this->pdf)($charge, sendEmail: $mode !== self::NORMAL || ! $this->withNfse($charge));
        }

        if ($mode === self::NORMAL && $this->withNfse($charge) && ! FinChargesNfse::hasActiveEmission($charge->id)) {
            if ($error = $this->issue->withInstruction($charge, $this->userId, sendEmail: true)) {
                $this->nfseAlerts[] = NfseAlert::row($charge, $error);
            }
        }

        $this->created[] = $this->summaryRow($b, $sum + $iof);

        return true;
    }

    /**
     * processaItensCobranca: um item por despesa com venda > 0 (do BL, quando houver); desconto fica negativo e
     * comissão não vira item. Item zerado avisa o financeiro e descarta a IC (no handling, entra zerado).
     * No frete separado (geraFrete) tudo entra como está.
     *
     * @return bool se algum item ficou zerado
     */
    private function items(FinCharges $charge, Embarque $b, ?stdClass $bl, int $clientId, Collection $expenses, string $mode): bool
    {
        $zeroed = false;

        foreach ($expenses as $e) {
            if ((float) $e->selling_rate <= 0 || ($bl && ! $this->belongsToBl($e, $bl))) {
                continue;
            }

            $expense = (int) $e->ref_expense;

            if ($mode !== self::FREIGHT && in_array($expense, ItemPricing::COMMISSION_EXPENSES, true)) {
                continue;
            }

            $unit = $this->pricing->unitValue($e);
            $rate = $this->rates->rate($clientId, $e->ref_currency_sr, $charge);
            $value = $this->pricing->debit($e, $rate, $unit, $bl);

            if ($mode !== self::FREIGHT && in_array($expense, ItemPricing::DISCOUNT_EXPENSES, true)) {
                $unit = -abs($unit);
                $value = -abs((float) $value);
            } elseif ($mode === self::NORMAL && (float) $value == 0) {
                BookingChecks::notify(' Item de Cobrança zerado', "A cobrança da reserva {$b->booking} não pôde ser gerada. A despesa {$e->despesa?->name} ficou com valor zerado.");
                $zeroed = true;

                continue;
            }

            DB::table('fin_charges_items')->insert([
                'ref_charge' => $charge->id,
                'ref_expense' => $e->id,
                'unitary_value' => $unit,
                'ref_currency' => $e->ref_currency_sr,
                'current_rate_value' => $rate,
                'item_value' => $value,
                'created_at' => now()->toDateString(),
            ]);
        }

        return $zeroed;
    }

    /** A despesa é deste BL: sem BL/container próprios, ou o BL/container é dele. */
    private function belongsToBl(FinExpensesValues $e, stdClass $bl): bool
    {
        if ($e->ref_bl && (int) $e->ref_bl !== (int) $bl->bl_id) {
            return false;
        }

        return ! $e->ref_container || (int) DB::table('container')->where('container_id', $e->ref_container)->value('bl_id') === (int) $bl->bl_id;
    }

    /** verificaCobrancaBooking: "Extra" se a reserva (ou o BL) já tem IC; seguro é sempre "Normal". */
    private function chargeType(Embarque $b, ?stdClass $bl): int
    {
        $exists = $b->tipo_embarque !== 's' && FinCharges::where('ref_booking', $b->embarque_id)
            ->when($bl, fn ($q) => $q->where('ref_bl', $bl->bl_id))
            ->exists();

        return $exists ? self::TYPE_EXTRA : self::TYPE_NORMAL;
    }

    /**
     * NfseEmissaoService::deveEmitirNfseNaGeracaoCobranca: IMPO/EXPO, cliente sem bloqueio nem exceção,
     * e com prazo/boleto (ou armador que não recalcula). Também decide se o e-mail da IC espera a NFS-e.
     */
    private function withNfse(FinCharges $charge): bool
    {
        $b = $charge->embarque;

        return in_array($b->impor_expor, ['i', 'e'], true)
            && ! ServiceTaker::blockedByRule($b)
            && ! IssueNfse::clientInException($charge)
            && EmissionGroups::issuedWithInstruction($b);
    }

    /**
     * alertaTomadorNfseImpedeGeracao: se a NFS-e vai sair com a IC, o tomador precisa estar válido antes de criá-la.
     * A reserva fica pendente (ctr_desp = 1) para a próxima geração.
     */
    private function takerBlocks(Embarque $b, ?stdClass $bl): bool
    {
        $charge = (new FinCharges)->forceFill(['ref_booking' => $b->embarque_id, 'ref_bl' => $bl?->bl_id])->setRelation('embarque', $b);

        if (! $this->withNfse($charge)) {
            return false;
        }

        try {
            $this->taker->validate($this->taker->default($charge));

            return false;
        } catch (DomainException $e) {
            $this->nfseAlerts[] = NfseAlert::bookingRow($b, (string) ($bl->bl ?? ''), $e->getMessage());

            return true;
        }
    }

    private function log(FinCharges $charge, string $action, string $history): void
    {
        FinChargesLog::forceCreate([
            'ref_charge' => $charge->id,
            'acao' => $action,
            'historico' => $history,
            'status_novo' => ChargeStatus::Open->value,
            'created_at' => now(),
            'created_by' => $this->userId,
        ]);
    }

    /** geraLinhaTabelaCobranca. */
    private function summaryRow(Embarque $b, float $total): string
    {
        $client = DB::table('pessoa')->where('pessoa_id', $b->cliente_id)->first(['nome', 'cnpj']);
        $cells = [
            $b->booking,
            'R$ '.number_format($total, 2, ',', '.'),
            $client ? "{$client->nome} ({$client->cnpj})" : '',
            (string) DB::table('navio')->where('navio_id', $b->navio_id)->value('nome'),
            $this->details->port($b->porto_origem_id),
            $this->details->port($b->porto_destino_id),
            (string) DB::table('armador')->where('armador_id', $b->armador_id)->value('nome'),
        ];
        $background = count($this->created) % 2 === 0 ? '#f6f6f6' : '#ffffff';

        return "<tr style='background-color:{$background}'>".collect($cells)->map(fn ($v) => '<td>'.e((string) $v).'</td>')->implode('').'</tr>';
    }

    /** E-mail "N cobrança(s) gerada(s) com sucesso" ao financeiro. */
    private function summary(): void
    {
        if ($this->created === []) {
            return;
        }

        BookingChecks::notify(count($this->created).' cobrança(s) gerada(s) com sucesso',
            'As seguintes cobranças foram geradas com sucesso. <table cellpadding=1><tr>'
            .'<th>Booking</th><th>Valor Total</th><th>Cliente</th><th>Navio</th><th>POO</th><th>POD</th><th>Armador</th></tr>'
            .implode('', $this->created).'</table>');
    }
}

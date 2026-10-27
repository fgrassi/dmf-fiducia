<?php

namespace App\Actions\Cobranca\Generation;

use App\Actions\Cobranca\Items\ItemPricing;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Checagens da geração da IC (Cobranca legado): verificaDespesasOrfas, verificaDespesas, verificaDespesasObrigatorias,
 * verificaLiberacao e verificaCotacoes. Cada problema vira um e-mail ao financeiro, como no legado.
 */
class BookingChecks
{
    public const FINANCE_EMAIL = 'financeiro@dmflog.com.br';

    /** Regras de despesa do frete separado (separa_taxas): 4 gera a IC de frete; 4 e 9 contam como pendentes. */
    private const FREIGHT_RULE = 4;

    private const FREIGHT_PENDING_RULES = [4, 9];

    /** fin_expenses_values.ref_due_to = 10: despesa que não vai para a cobrança. */
    private const NOT_CHARGED_DUE_TO = 10;

    /** Despesas de comissão e a(s) despesa(s) de frete que precisam existir com o mesmo equipamento. */
    private const COMMISSION_NEEDS = [33 => [1], 1048 => [1], 356 => [200], 387 => [73, 273], 419 => [41]];

    public function __construct(private ChargeRates $rates) {}

    /** billing que entra na IC: IMPO cobra do consignee (c), demais do shipper (p); seguro não filtra. */
    public static function billing(Embarque $b): ?string
    {
        return match (true) {
            $b->tipo_embarque === 's' => null,
            $b->impor_expor === 'i' => 'c',
            default => 'p',
        };
    }

    /** Despesas da reserva que ainda não estão em nenhuma IC. */
    public static function unbilled(Embarque $b): Builder
    {
        return DB::table('fin_expenses_values as f')
            ->where('f.ref_embarque', $b->embarque_id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_charges_items as fi')->whereColumn('fi.ref_expense', 'f.id'));
    }

    /** verificaDespesasOrfas: há despesa a cobrar fora de IC (sem comissões e sem as "não cobrar"). */
    public function hasPending(Embarque $b): bool
    {
        // verificaDespesasOrfas só filtra o billing em IMPO/EXPO
        $billing = in_array($b->impor_expor, ['i', 'e'], true) ? self::billing($b) : null;

        return self::unbilled($b)
            ->where('f.selling_rate', '>', 0)
            ->where(fn ($q) => $q->where('f.ref_due_to', '<>', self::NOT_CHARGED_DUE_TO)->orWhereNull('f.ref_due_to'))
            ->whereNotIn('f.ref_expense', ItemPricing::COMMISSION_EXPENSES)
            ->when($billing, fn ($q, $v) => $q->where('f.billing', $v))
            ->exists();
    }

    /** verificaDespesasOrfasFrete: há frete (regras 4/9) a cobrar fora de IC. */
    public function hasPendingFreight(Embarque $b): bool
    {
        $billing = in_array($b->impor_expor, ['i', 'e'], true) ? self::billing($b) : null;

        return self::unbilled($b)
            ->join('regra_despesa_cobranca_item as rd', 'rd.despesa_id', '=', 'f.ref_expense')
            ->where('f.selling_rate', '>', 0)
            ->whereIn('rd.regra_despesa_cobranca_id', self::FREIGHT_PENDING_RULES)
            ->when($billing, fn ($q, $v) => $q->where('f.billing', $v))
            ->exists();
    }

    /** getDespesasIdsFrete: despesas da regra de frete ainda fora de IC. @return list<int> */
    public function freightIds(Embarque $b): array
    {
        return self::unbilled($b)
            ->join('regra_despesa_cobranca_item as rd', 'rd.despesa_id', '=', 'f.ref_expense')
            ->where('rd.regra_despesa_cobranca_id', self::FREIGHT_RULE)
            ->pluck('f.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** getDespesasIds: despesas fora de IC (opcionalmente só as escolhidas). @param  ?list<int>  $only  @return list<int> */
    public function pendingIds(Embarque $b, ?array $only): array
    {
        return self::unbilled($b)
            ->when($only !== null, fn ($q) => $q->whereIn('f.id', $only))
            ->pluck('f.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** verificaLiberacao: a primeira liberação do embarque decide; sem nenhuma, está liberado. */
    public function released(Embarque $b): bool
    {
        $status = DB::table('liberacao_embarque')->where('embarque_id', $b->embarque_id)->orderBy('liberacao_embarque_id')->value('status');

        return $status === null || (bool) $status;
    }

    /** verificaCotacoes (sem IC): moedas das despesas da reserva com cotação válida na data. @param  ?list<int>  $only */
    public function ratesUpToDate(Embarque $b, ?array $only): bool
    {
        $currencies = DB::table('fin_expenses_values')
            ->where('ref_embarque', $b->embarque_id)
            ->where('selling_rate', '>', 0)
            ->where('ref_currency_sr', '<>', 1)
            // getBookingCurrencies só filtra o billing em marítimo, aéreo e desembaraço
            ->when(in_array($b->tipo_embarque, ['m', 'a', 'd'], true), fn ($q) => $q->where('billing', $b->impor_expor === 'i' ? 'c' : 'p'))
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->distinct()
            ->pluck('ref_currency_sr');

        return $this->rates->currenciesUpToDate($b, $currencies);
    }

    /** verificaDespesas + verificaDespesasObrigatorias: a reserva tem despesas, BLs e equipamentos coerentes. */
    public function expensesConsistent(Embarque $b): bool
    {
        $expenses = FinExpensesValues::with('despesa')
            ->where('ref_embarque', $b->embarque_id)
            ->where(fn ($q) => $q->where('ref_due_to', '<>', self::NOT_CHARGED_DUE_TO)->orWhereNull('ref_due_to'))
            ->orderBy('id')
            ->get();

        if ($expenses->isEmpty()) {
            return $this->fail('Booking sem despesas cadastradas: '.$b->booking, "O embarque {$b->booking} não possui despesas cadastradas");
        }

        $maritime = $b->tipo_embarque === 'm';
        $bls = DB::table('bl')->where('embarque_id', $b->embarque_id)->whereNotNull('bl')->pluck('bl_id');
        $unity = fn (int $u) => $expenses->contains(fn ($e) => (int) $e->despesa?->ref_unity === $u);
        $equipments = DB::table('mercadoria')->where('embarque_id', $b->embarque_id)->pluck('tipo_container_id')->map(fn ($v) => (int) $v);

        $problem = match (true) {
            $maritime && $bls->isEmpty() => ['Booking sem BLs cadastrados:', 'não possui BLS cadastrados.'],
            $unity(1) && $b->qtd_bl < 1 => ['Booking sem BLs cadastrados: ', 'não possui quantidade de BLS cadastrados e uma ou mais despesas sao do tipo BL.'],
            // legado: o flag é refeito a cada despesa CNT com equipamento, então vale a última delas
            ($cnt = $expenses->last(fn ($e) => (int) $e->despesa?->ref_unity === 2 && $e->ref_equipment > 0)) && ! $equipments->contains((int) $cnt->ref_equipment) => ['Booking com equipamentos inconsistentes: ', 'possui despesas com equipamentos não cadastrados.'],
            $expenses->contains(fn ($e) => blank($e->billing)) => ['Booking com despesas inconsistentes: ', 'possui despesas sem o tipo de Billing.'],
            $unity(6) => ['Booking com despesas inconsistentes: ', 'possui despesas do tipo DOC'],
            ! $this->commissionHasFreight($expenses) => ['Booking com despesas inconsistentes: ', 'possui Arbitrary Fee sem Frete.'],
            $b->impor_expor === 'i' && $maritime && $b->revisao_despesas === 'n' => ['Booking sem revisão de despesas: ', 'não teve suas despesas revisadas.'],
            $unity(3) && $b->qtd_bl < 1 => ['Booking com despesas inconsistentes: ', 'possui despesas W/M e não possui BL cadastrado.'],
            $maritime && $bls->contains(fn ($bl) => ! DB::table('container')->where('bl_id', $bl)->exists()) => ['Booking sem equipamentos no BL: ', 'não possui equipamentos cadastrados em pelo menos um de seus BLs.'],
            default => null,
        };

        if ($problem) {
            return $this->fail($problem[0].$b->booking, "O embarque {$b->booking} {$problem[1]} Por favor revise esse processo.");
        }

        return $this->mandatoryExpenses($b);
    }

    /**
     * Comissão (Arbitrary Fee etc.) precisa do frete correspondente no mesmo equipamento.
     * ponytail: como no legado, só a última despesa da reserva é conferida (o flag era sobrescrito no loop); conferir todas se o financeiro pedir.
     *
     * @param  Collection<int, FinExpensesValues>  $expenses
     */
    private function commissionHasFreight(Collection $expenses): bool
    {
        $last = $expenses->last();
        $needs = self::COMMISSION_NEEDS[(int) $last->ref_expense] ?? null;

        return $needs === null || $expenses->contains(fn ($e) => in_array((int) $e->ref_expense, $needs, true) && $e->ref_equipment == $last->ref_equipment);
    }

    /** verificaDespesasObrigatorias: IMPO marítima precisa de ao menos uma despesa de cada regra obrigatória. */
    private function mandatoryExpenses(Embarque $b): bool
    {
        if ($b->impor_expor !== 'i' || $b->tipo_embarque !== 'm') {
            return true;
        }

        $missing = DB::table('regra_despesa_cobranca as r')
            ->where('r.obrigatoria', 1)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('regra_despesa_cobranca_item as ri')
                ->join('fin_expenses_values as f', 'f.ref_expense', '=', 'ri.despesa_id')
                ->whereColumn('ri.regra_despesa_cobranca_id', 'r.regra_despesa_cobranca_id')
                ->where('f.ref_embarque', $b->embarque_id))
            ->orderBy('r.regra_despesa_cobranca_id')
            ->value('r.nome');

        if ($missing === null) {
            return true;
        }

        // getRecipientList('author'): o autor da reserva também recebe
        $author = $b->intIdUser ? DB::table('usuario')->where('usuario_id', $b->intIdUser)->value('email') : null;

        return $this->fail(
            'Despesas obrigatória não cadastrada: '.$b->booking,
            "As despesas do tipo {$missing} não foram cadastradas para esse embarque. Favor revistar.",
            filter_var($author, FILTER_VALIDATE_EMAIL) ? [$author] : [],
        );
    }

    /** geraNotificacao: e-mail ao financeiro. @param  list<string>  $also */
    public static function notify(string $title, string $message, array $also = []): void
    {
        Mail::html($message, fn ($mail) => $mail->to(array_values(array_unique([...$also, self::FINANCE_EMAIL])))->subject("DMF Logistics: {$title}"));
    }

    /** @param  list<string>  $also */
    private function fail(string $title, string $message, array $also = []): bool
    {
        self::notify($title, $message, $also);

        return false;
    }
}

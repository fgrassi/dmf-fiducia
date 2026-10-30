<?php

namespace App\Actions\AccountingCovers;

use App\Models\CapaProcesso;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * save (legado): grava a capa com o que o usuário marcou, trava as cobranças e liga as baixas de TA aos itens de despesa.
 *
 * Diferenças do legado: só aceita o que `ListPendingCoverValues` oferece (o legado confia no que o navegador manda; o valor
 * das receitas e despesas continua editável, o nome vem da listagem), a multa não soma a taxa de boleto (D13) e a capa
 * entra numa transação.
 *
 * @phpstan-type Item array{id: int, value: float|int|string, ta_payment_id?: int}
 * @phpstan-type Selection array{
 *     currency_id: int,
 *     commission: float|int|string,
 *     rate: float|int|string,
 *     revenues?: list<Item>,
 *     expenses?: list<Item>,
 *     covered_expenses?: list<Item>,
 *     purchase_iof?: list<int>,
 *     iof?: list<int>,
 *     discount?: list<int>,
 *     interest?: list<int>,
 *     bank_fee?: list<int>,
 *     fine?: list<int>,
 * }
 */
class CreateCover
{
    /** Coluna da capa e flag da cobrança de cada extra marcável. */
    private const EXTRAS = [
        'iof' => ['valor_iof', 'ctr_valor_iof'],
        'discount' => ['desconto', 'ctr_desconto'],
        'interest' => ['valor_juros', 'ctr_juros'],
        'bank_fee' => ['valor_tax_boleto', 'ctr_tax_boleto'],
        'fine' => ['valor_multa', 'ctr_fine'],
    ];

    public function __construct(private ListPendingCoverValues $pending) {}

    /**
     * @param  Selection  $selection
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $selection, ?int $userId = null): CapaProcesso
    {
        $pending = ($this->pending)($booking);
        $revenues = $this->chosen($selection['revenues'] ?? [], $pending['revenues']);
        $expenses = $this->chosen($selection['expenses'] ?? [], $pending['expenses']);
        $covered = $this->chosen($selection['covered_expenses'] ?? [], $pending['covered_expenses']);

        return DB::transaction(function () use ($booking, $selection, $userId, $pending, $revenues, $expenses, $covered) {
            $rate = (float) $selection['rate'];

            $cover = CapaProcesso::create([
                'embarque_id' => $booking->getKey(),
                'moeda_id' => $selection['currency_id'],
                'numero' => $this->nextNumber(),
                'valor_cotacao' => $rate,
                'comissoes' => (float) $selection['commission'],
                'receitas' => $this->notesTotal($booking),
                'created_at' => now(),
                'created_by' => $userId,
                'payment_date' => $this->paymentDate($booking),
                'valor_fiducia' => $this->fiduciaDiscount($booking, $selection['currency_id'], $rate),
            ]);

            foreach ($revenues as $item) {
                DB::table('capa_processo_receita_item')->insert($this->itemRow($cover, $item));
            }

            foreach ([...$expenses, ...$covered] as $item) {
                $itemId = DB::table('capa_processo_despesa_item')->insertGetId($this->itemRow($cover, $item));

                if (isset($item['ta_payment_id'])) {
                    DB::table('ta_baixa')->where('ta_baixa_id', $item['ta_payment_id'])->whereNull('capa_processo_despesa_item_id')->update(['capa_processo_despesa_item_id' => $itemId]);
                }
            }

            $purchaseIof = array_sum(array_intersect_key($pending['purchase_iof'], array_flip($selection['purchase_iof'] ?? [])));

            if ($purchaseIof) {
                $cover->update(['valor_iof_compra' => $purchaseIof]);
            }

            foreach (self::EXTRAS as $kind => [$column, $flag]) {
                $chosen = $this->chosenCharges($selection[$kind] ?? [], $pending[$kind]);

                if ($chosen) {
                    DB::table('fin_charges')->whereIn('id', array_keys($chosen))->update([$flag => 1]);
                    $cover->update([$column => array_sum($chosen)]);
                }
            }

            return $cover;
        });
    }

    /**
     * Cada linha marcada precisa existir na listagem (por despesa, tantas vezes quanto ela aparece); o nome vem de lá.
     *
     * @param  list<Item>  $submitted
     * @param  list<array{id: int, name: string, value: float, ta_payment_id?: int}>  $offered
     * @return list<array{id: int, name: string, value: float, ta_payment_id?: int}>
     */
    private function chosen(array $submitted, array $offered): array
    {
        $chosen = [];

        foreach ($submitted as $item) {
            $key = collect($offered)->search(fn (array $o, int $k) => ! isset($chosen[$k])
                && $o['id'] === (int) $item['id']
                && ($o['ta_payment_id'] ?? null) === (isset($item['ta_payment_id']) ? (int) $item['ta_payment_id'] : null));

            if ($key === false) {
                throw new DomainException('Há itens marcados que já entraram em outra capa. Recarregue a reserva.');
            }

            $chosen[$key] = ['value' => (float) $item['value']] + $offered[$key];
        }

        return array_values($chosen);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<array{id: int, value: float}>  $offered
     * @return array<int, float> valor por cobrança
     *
     * @throws DomainException
     */
    private function chosenCharges(array $ids, array $offered): array
    {
        $values = array_column($offered, 'value', 'id');

        if (array_diff(array_map('intval', $ids), array_keys($values))) {
            throw new DomainException('Há cobranças marcadas que já entraram em outra capa. Recarregue a reserva.');
        }

        return array_intersect_key($values, array_flip(array_map('intval', $ids)));
    }

    /** @param  array{id: int, name: string, value: float}  $item */
    private function itemRow(CapaProcesso $cover, array $item): array
    {
        return ['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => $item['id'], 'nome' => $item['name'], 'valor' => $item['value']];
    }

    /** getMaiorNumero (legado): maior número entre as capas (normais e manuais) ainda sem pasta, mais um. */
    private function nextNumber(): int
    {
        return 1 + (int) max(
            DB::table('capa_processo')->whereNull('capa_processo_arquivo_id')->max('numero'),
            DB::table('capa_processo_manual')->whereNull('capa_processo_arquivo_id')->max('numero'),
        );
    }

    /** Receitas no exterior: débito - crédito na exportação, o contrário na importação (notas de débito e crédito da reserva). */
    private function notesTotal(Embarque $booking): float
    {
        $debit = (float) DB::table('fin_debit_notes')->where('ref_embarque', $booking->getKey())
            ->selectRaw('COALESCE(SUM(COALESCE(due_to_dmf, 0) + COALESCE(handling, 0) + COALESCE(origin_comission, 0) + COALESCE(due_to_shipper_agent, 0) + COALESCE(exw_expenses, 0)), 0) as total')->value('total');
        $credit = (float) DB::table('fin_credit_notes')->where('ref_embarque', $booking->getKey())
            ->selectRaw('COALESCE(SUM(COALESCE(due_to_dmf_agent, 0) + COALESCE(due_to_cnee_agent, 0)), 0) as total')->value('total');

        return $booking->impor_expor === 'e' ? $debit - $credit : $credit - $debit;
    }

    /** Data de pagamento da primeira despesa paga das ordens da reserva (o legado pega a primeira linha, agrupada por despesa). */
    private function paymentDate(Embarque $booking): ?string
    {
        return DB::table('fin_payment_order_items as fpoi')
            ->join('fin_payment_orders as fpo', 'fpo.id', '=', 'fpoi.ref_payment_order')
            ->where('fpo.ref_embarque', $booking->getKey())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fin_payment_order_items_disabled as d')
                ->whereColumn('d.ref_fin_payment_order', 'fpoi.ref_payment_order')
                ->whereColumn('d.ref_expense', 'fpoi.ref_expense')
                ->where('d.status', 0))
            ->orderBy('fpoi.ref_expense')
            ->value('fpo.payment_date');
    }

    /** Embarque::getValorDescontoFiducia (legado): só marítimo, cliente com desconto e uma única vez por reserva. */
    private function fiduciaDiscount(Embarque $booking, int $currencyId, float $rate): float
    {
        $discount = $booking->tipo_embarque === 'm' && $booking->cliente_id
            ? DB::table('fiducia_desconto')->where('pessoa_id', $booking->cliente_id)->first()
            : null;

        if (! $discount || CapaProcesso::where('embarque_id', $booking->getKey())->where('valor_fiducia', '>', 0)->exists()) {
            return 0;
        }

        $factor = $discount->tipo_desconto === 'p'
            ? 1
            : DB::table('container as c')->join('bl', 'bl.bl_id', '=', 'c.bl_id')->where('bl.embarque_id', $booking->getKey())->count();

        return (float) $discount->valor * $factor * ($discount->moeda_id > 1 ? $rate : 1);
    }
}

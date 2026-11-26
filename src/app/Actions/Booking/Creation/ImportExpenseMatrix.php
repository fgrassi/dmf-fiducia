<?php

namespace App\Actions\Booking\Creation;

use App\Actions\Notices\SendNoticeMail;
use App\Enums\Alert;
use App\Models\Embarque;
use App\Models\FinExpensesValues;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * booking/importExpensesMatrix (legado): na criação da reserva marítima, lança as despesas da matriz do armador no porto, das
 * matrizes do cliente, do agente e do despachante (uma vez por mercadoria) e o depósito de garantia da importação.
 *
 * Não portado: o ramo "despesas revisadas" (só e-mail, sem lançar). A reserva nova nasce sem revisão; ele entra quando a
 * edição da reserva for portada.
 */
class ImportExpenseMatrix
{
    /** Depósito de garantia (fin_expenses). */
    private const GUARANTEE_EXPENSE = 27;

    /** embarque.garantee => dias cobertos. */
    private const GUARANTEE_DAYS = ['3' => 30, '4' => 45, '5' => 50, '6' => 60, '9' => 90];

    private const BILLINGS = ['p' => 'PP', 'a' => 'PA', 'c' => 'CC', 'f' => 'FI'];

    private const DUE_TO_AGENT = 1;

    private const DUE_TO_CARRIER = 3;

    private const DUE_TO_BROKER = 5;

    private const DUE_TO_DMF = 7;

    public function __construct(private SendNoticeMail $send) {}

    public function __invoke(Embarque $booking, int $userId): void
    {
        $goods = DB::table('mercadoria')->where('embarque_id', $booking->getKey())->orderBy('mercadoria_id')->pluck('tipo_container_id');

        $created = collect($this->carrier($booking, $goods, $userId));

        foreach ($goods as $equipment) {
            $created->push(
                ...($booking->impor_expor === 'e' ? $this->exportClient($booking, $equipment, $userId) : []),
                ...$this->agent($booking, $equipment, $userId),
                ...$this->importClient($booking, $equipment, $userId),
                ...$this->broker($booking, $equipment, $userId),
            );
        }

        if ($created->isNotEmpty()) {
            DB::afterCommit(fn () => $this->notifyAuthor($booking, $created));
        }

        if ($booking->impor_expor === 'i' && $this->guarantee($booking, $goods, $userId)) {
            DB::afterCommit(fn () => $this->notifyGuarantee($booking));
        }
    }

    /** @return list<FinExpensesValues> */
    private function carrier(Embarque $booking, Collection $goods, int $userId): array
    {
        $export = $booking->impor_expor === 'e';
        $port = $export ? $booking->porto_origem_id : $booking->porto_destino_id;

        if (! $booking->armador_id || ! $port) {
            return [];
        }

        $date = ($export ? $booking->saida_prevista : $booking->chegada_prevista) ?: today()->toDateString();
        $originCountry = DB::table('porto')->where('porto_id', $booking->porto_origem_id)->value('ref_pais');

        $matrix = DB::table('fin_expenses_matrix as m')
            ->join('cmn_armador_porto as p', 'p.id', '=', 'm.ref_armador_porto')
            ->where('p.ref_armador', $booking->armador_id)
            ->where('p.ref_porto', $port)
            ->whereIn('m.type', ['b', $booking->impor_expor])
            ->where('m.due_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('m.due_to')->orWhere('m.due_to', '>', $date))
            ->orderBy('m.id')
            ->get('m.*');

        $created = [];

        foreach ($matrix as $row) {
            if ($row->pais_origem_id !== null && (int) $row->pais_origem_id !== (int) $originCountry) {
                continue;
            }

            if ($row->ref_equipamento && ! $goods->contains($row->ref_equipamento)) {
                continue;
            }

            $exists = $this->expenses($booking)
                ->where('ref_expense', $row->ref_expense)
                ->where('ref_currency_br', $row->ref_currency)
                ->where('buying_rate', $row->expense_value)
                ->when($row->ref_equipamento, fn (Builder $q, $equipment) => $q->where('ref_equipment', $equipment))
                ->exists();

            if (! $exists) {
                $created[] = $this->add($booking, $userId, [
                    'ref_expense' => $row->ref_expense,
                    'buying_rate' => $row->expense_value,
                    'ref_currency_br' => $row->ref_currency,
                    'ref_equipment' => $row->ref_equipamento,
                    'billing' => $row->billing,
                    'ref_due_to' => self::DUE_TO_CARRIER,
                    ...$this->visibility($booking),
                ]);
            }
        }

        return $created;
    }

    /** Venda padrão do cliente na exportação, no porto de origem. @return list<FinExpensesValues> */
    private function exportClient(Embarque $booking, int $equipment, int $userId): array
    {
        $matrix = $this->personMatrix($booking, $booking->cliente_id, $equipment)
            ->where(fn (Builder $q) => $q->whereNull('m.porto_id')->orWhere('m.porto_id', $booking->porto_origem_id))
            ->whereIn('m.impor_expor', ['e', 'b'])
            ->get('m.*');

        return $this->addFrom($booking, $userId, $matrix, $equipment, false, fn (object $row) => [
            'ref_currency_sr' => $row->ref_currency,
            'selling_rate' => $row->expense_value,
            'ref_equipment' => $row->ref_equipamento ? $equipment : null,
            'ref_due_to' => self::DUE_TO_DMF,
            ...$this->visibility($booking),
        ]);
    }

    /** @return list<FinExpensesValues> */
    private function agent(Embarque $booking, int $equipment, int $userId): array
    {
        $matrix = $this->personMatrix($booking, $booking->pessoa_id_representante, $equipment)->get('m.*');

        return $this->addFrom($booking, $userId, $matrix, $equipment, true, fn (object $row) => [
            'ref_currency_br' => $row->ref_currency,
            'buying_rate' => $row->expense_value,
            'ref_equipment' => $equipment,
            'ref_due_to' => self::DUE_TO_AGENT,
            ...$this->visibility($booking),
        ]);
    }

    /** Matriz do cliente no porto de destino. Como no legado, roda também na exportação. @return list<FinExpensesValues> */
    private function importClient(Embarque $booking, int $equipment, int $userId): array
    {
        $matrix = $this->personMatrix($booking, $booking->cliente_id, $equipment)
            ->whereIn('m.impor_expor', ['i', 'b'])
            ->where(fn (Builder $q) => $q->whereNull('m.porto_id')->orWhere('m.porto_id', $booking->porto_destino_id))
            ->get('m.*');

        return $this->addFrom($booking, $userId, $matrix, $equipment, false, fn (object $row) => [
            'ref_currency_sr' => $row->ref_currency,
            'selling_rate' => $row->expense_value,
            'ref_currency_br' => $row->moeda_compra_id,
            'buying_rate' => $row->valor_compra,
            'ref_equipment' => $row->ref_equipamento ? $equipment : null,
            'ref_due_to' => self::DUE_TO_DMF,
            ...$this->visibility($booking),
        ]);
    }

    /** @return list<FinExpensesValues> */
    private function broker(Embarque $booking, int $equipment, int $userId): array
    {
        $brokerPort = DB::table('despachante_porto')
            ->where('id_porto', $booking->porto_destino_id)
            ->where('id_despachante', $booking->despachante_dmf_id)
            ->value('id');

        if (! $brokerPort) {
            return [];
        }

        $matrix = $this->current(DB::table('matriz_despesa_despachante as m'), $equipment, ['i', 'b'])
            ->where('m.ref_despachante_porto', $brokerPort)
            ->get('m.*');

        return $this->addFrom($booking, $userId, $matrix, $equipment, false, fn (object $row) => [
            'ref_currency_br' => $row->ref_currency,
            'buying_rate' => $row->expense_value,
            'ref_equipment' => $equipment,
            'ref_due_to' => self::DUE_TO_BROKER,
            'ctrl' => 0,
            'ctrl2' => 0,
        ]);
    }

    private function personMatrix(Embarque $booking, ?int $personId, int $equipment): Builder
    {
        return $this->current(DB::table('matriz_despesa_pessoa as m'), $equipment, [$booking->impor_expor, 'b'])
            ->where('m.pessoa_id', $personId);
    }

    /** Linhas vigentes hoje, da operação e do equipamento (ou sem equipamento). */
    private function current(Builder $query, int $equipment, array $operations): Builder
    {
        $today = today()->toDateString();

        return $query
            ->join('fin_expenses as e', 'e.id', '=', 'm.ref_expense')
            ->whereIn('e.operation_type', $operations)
            ->where('m.due_from', '<=', $today)
            ->where(fn (Builder $q) => $q->whereNull('m.due_to')->orWhere('m.due_to', '>', $today))
            ->where(fn (Builder $q) => $q->whereNull('m.ref_equipamento')->orWhere('m.ref_equipamento', $equipment))
            ->orderBy('m.id');
    }

    /**
     * Lança as linhas que a reserva ainda não tem: mesma despesa e cobrança no equipamento (no agente, só o equipamento exato;
     * nas demais, também a despesa sem equipamento).
     *
     * @return list<FinExpensesValues>
     */
    private function addFrom(Embarque $booking, int $userId, Collection $matrix, int $equipment, bool $exactEquipment, callable $values): array
    {
        $created = [];

        foreach ($matrix as $row) {
            $exists = $this->expenses($booking)
                ->where('ref_expense', $row->ref_expense)
                ->where('billing', $row->billing)
                ->where(fn (Builder $q) => $q->where('ref_equipment', $equipment)->when(! $exactEquipment, fn (Builder $q) => $q->orWhereNull('ref_equipment')))
                ->exists();

            if (! $exists) {
                $created[] = $this->add($booking, $userId, ['ref_expense' => $row->ref_expense, 'billing' => $row->billing, ...$values($row)]);
            }
        }

        return $created;
    }

    private function guarantee(Embarque $booking, Collection $goods, int $userId): bool
    {
        $days = self::GUARANTEE_DAYS[(string) $booking->garantee] ?? 0;
        $added = false;

        foreach ($goods as $equipment) {
            $deposit = DB::table('deposito_garantia')->where('ref_armador', $booking->armador_id)->where('ref_equipment', $equipment)->orderBy('id')->first();

            if (! $days || ! $deposit || $this->expenses($booking)->where('ref_expense', self::GUARANTEE_EXPENSE)->where('ref_equipment', $equipment)->exists()) {
                continue;
            }

            $this->add($booking, $userId, [
                'ref_expense' => self::GUARANTEE_EXPENSE,
                'ref_equipment' => $equipment,
                'selling_rate' => $deposit->valor * $days,
                'ref_currency_sr' => $deposit->ref_currency,
                'billing' => 'c',
            ]);
            $added = true;
        }

        return $added;
    }

    private function expenses(Embarque $booking): Builder
    {
        return DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey());
    }

    /** Na exportação a despesa já nasce marcada para a Shipping e a Billing Instruction. */
    private function visibility(Embarque $booking): array
    {
        $flag = $booking->impor_expor === 'e' ? 1 : 0;

        return ['ctrl' => $flag, 'ctrl2' => $flag];
    }

    private function add(Embarque $booking, int $userId, array $values): FinExpensesValues
    {
        $expense = (new FinExpensesValues)->forceFill([
            ...$values,
            'ref_embarque' => $booking->getKey(),
            'created_by' => $userId,
            'created_at' => today()->toDateString(),
        ]);
        $expense->save();

        return $expense;
    }

    /** @param  Collection<int, FinExpensesValues>  $created */
    private function notifyAuthor(Embarque $booking, Collection $created): void
    {
        $email = DB::table('usuario')->where('usuario_id', $booking->intIdUser)->value('email');

        if (blank($email)) {
            return;
        }

        $rows = $created->map(function (FinExpensesValues $expense): string {
            $currency = DB::table('cmn_currencies')->where('id', $expense->ref_currency_br)->first();

            return '<tr>'.collect([
                DB::table('fin_expenses')->where('id', $expense->ref_expense)->value('name'),
                $currency ? "{$currency->name} ({$currency->symbol})" : '',
                number_format((float) $expense->buying_rate, 2, ',', '.'),
                DB::table('tipo_container')->where('tipo_container_id', $expense->ref_equipment)->value('nome'),
                self::BILLINGS[$expense->billing] ?? '',
            ])->map(fn ($value) => '<td>'.e((string) $value).'</td>')->implode('').'</tr>';
        })->implode('');

        $client = DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('nome');

        ($this->send)(
            "Revisão de Reserva: {$booking->booking} ({$client})",
            '<h3>Matriz de Despesas</h3><p>Prezado usuário.<br/>A reserva '.e((string) $booking->booking).' teve despesas atualizadas a partir da Matriz de Despesas.<br/>'
                .'É necessário verificar os valores de compra (B/R) e venda (S/R).<br/>As seguintes despesas foram importadas da Matriz de Despesas:</p>'
                .'<table border="1"><tr><th>Despesa</th><th>Moeda</th><th>B/R</th><th>Equipamento</th><th>Billing</th></tr>'.$rows.'</table>',
            [$email],
            from: 'system@dmflog.com.br',
            fromName: 'DMF Logistics do Brasil - System Notification',
        );
    }

    private function notifyGuarantee(Embarque $booking): void
    {
        Alert::GuaranteeDepositReview->enabled() && ($this->send)(
            "Revisão de Reserva - Depósito de Garantia: {$booking->booking}",
            '<h3>Matriz de Despesas - Depósito de Garantia</h3><p>Prezado usuário.<br/>A reserva '.e((string) $booking->booking)
                .' teve despesas atualizadas a partir da Matriz de Despesas - Depósito de Garantia.<br/>É necessário verificar os valores de venda (S/R).</p>',
            Alert::GuaranteeDepositReview->recipients(),
            cc: Alert::GuaranteeDepositReview->copies(),
            from: 'system@dmflog.com.br',
            fromName: 'DMF Logistics do Brasil - System Notification',
        );
    }
}

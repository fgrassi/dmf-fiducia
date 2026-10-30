<?php

namespace Tests\Feature\Models;

use App\Models\CapaProcesso;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CapaProcessoTest extends TestCase
{
    use LegacySchema;

    public function test_result_adds_charges_extras_and_subtracts_expenses_with_fiducia_iof_and_financial_cost(): void
    {
        $cover = $this->cover([
            'valor_iof' => 10, 'desconto' => 5, 'valor_juros' => 2, 'valor_tax_boleto' => 3, 'valor_multa' => 1,
            'valor_fiducia' => 4, 'valor_iof_compra' => 6, 'custo_financeiro' => -7, 'receitas' => 100, 'comissoes' => 20, 'valor_cotacao' => 5,
        ]);
        $this->items('capa_processo_receita_item', $cover, [1000, 500]);
        $this->items('capa_processo_despesa_item', $cover, [300, 200]);

        $cover = CapaProcesso::withItemTotals()->find($cover->getKey());

        $this->assertEqualsWithDelta(1511, $cover->receiptsTotal(), 0.001);
        $this->assertEqualsWithDelta(517, $cover->expensesTotal(), 0.001);
        $this->assertEqualsWithDelta(994, $cover->result(), 0.001);
        $this->assertEqualsWithDelta(600, $cover->rate(), 0.001);
        $this->assertEqualsWithDelta(1594, $cover->coverResult(), 0.001);
    }

    public function test_negative_extras_are_ignored_and_totals_work_without_the_scope(): void
    {
        $cover = $this->cover(['valor_iof' => -10, 'desconto' => -5, 'valor_juros' => null]);
        $this->items('capa_processo_receita_item', $cover, [100]);
        $this->items('capa_processo_despesa_item', $cover, [40]);

        $this->assertEqualsWithDelta(60, $cover->fresh()->result(), 0.001);
    }

    public function test_process_result_sums_every_cover_of_the_booking(): void
    {
        $first = $this->cover(['numero' => 1]);
        $second = $this->cover(['numero' => 2]);
        $other = $this->cover(['numero' => 3, 'embarque_id' => 2]);
        $this->items('capa_processo_receita_item', $first, [100]);
        $this->items('capa_processo_receita_item', $second, [50]);
        $this->items('capa_processo_receita_item', $other, [999]);

        $this->assertEqualsWithDelta(150, $first->processResult(), 0.001);
    }

    /** @param array<string, mixed> $attributes */
    private function cover(array $attributes = []): CapaProcesso
    {
        return CapaProcesso::create($attributes + ['embarque_id' => 1, 'moeda_id' => 2, 'numero' => 1]);
    }

    /** @param array<int, float> $values */
    private function items(string $table, CapaProcesso $cover, array $values): void
    {
        foreach ($values as $value) {
            DB::table($table)->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'Item', 'valor' => $value]);
        }
    }
}

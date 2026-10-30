<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\DeleteCover;
use App\Actions\AccountingCovers\UpdateCover;
use App\Models\CapaProcesso;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CoverEditActionsTest extends TestCase
{
    use LegacySchema;

    public function test_update_refreshes_the_summary_with_result_and_rate(): void
    {
        $cover = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 1, 'receitas' => 0, 'comissoes' => 0, 'valor_cotacao' => 0]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 1, 'nome' => 'THC', 'valor' => 1000]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 2, 'nome' => 'Frete', 'valor' => 400]);
        DB::table('resumo_resultado')->insert(['capa_processo_id' => $cover->getKey(), 'usd' => 0, 'rs' => 0, 'euro' => 0, 'resultado' => null]);

        app(UpdateCover::class)($cover, ['moeda_id' => 2, 'receitas' => 100, 'comissoes' => 20, 'valor_cotacao' => 5]);

        $summary = DB::table('resumo_resultado')->first();
        $this->assertEquals([100, 0, 600, 1200], [$summary->usd, $summary->euro, $summary->rs, $summary->resultado]);
    }

    public function test_update_of_number_leaves_the_summary_alone(): void
    {
        $cover = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 1]);
        DB::table('resumo_resultado')->insert(['capa_processo_id' => $cover->getKey(), 'rs' => 7]);

        app(UpdateCover::class)($cover, ['numero' => 9]);

        $this->assertSame(9, $cover->fresh()->numero);
        $this->assertEquals(7, DB::table('resumo_resultado')->value('rs'));
    }

    public function test_delete_removes_the_cover_and_releases_charges_and_ta_payments(): void
    {
        $cover = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 1]);
        $other = CapaProcesso::create(['embarque_id' => 1, 'moeda_id' => 1, 'numero' => 2]);
        $flags = ['ctr_valor_iof' => 1, 'ctr_desconto' => 1, 'ctr_juros' => 1, 'ctr_tax_boleto' => 1, 'ctr_fine' => 1];
        $base = ['ref_type' => 1, 'ref_booking' => 1, 'created_at' => '2026-10-01'];
        DB::table('fin_charges')->insert([['id' => 1] + $base + $flags, ['id' => 2] + $base + $flags]);
        DB::table('fin_charges_items')->insert([
            ['ref_charge' => 1, 'ref_expense' => 10, 'ref_currency' => 1, 'created_at' => '2026-10-01'],
            ['ref_charge' => 2, 'ref_expense' => 20, 'ref_currency' => 1, 'created_at' => '2026-10-01'],
        ]);
        DB::table('capa_processo_receita_item')->insert(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 10, 'nome' => 'THC', 'valor' => 1]);
        $itemId = DB::table('capa_processo_despesa_item')->insertGetId(['capa_processo_id' => $cover->getKey(), 'fin_expenses_values_id' => 11, 'nome' => 'Frete', 'valor' => 1]);
        DB::table('capa_processo_despesa_item')->insert(['capa_processo_id' => $other->getKey(), 'fin_expenses_values_id' => 20, 'nome' => 'Frete', 'valor' => 1]);
        DB::table('ta_baixa')->insert(['fin_expenses_values_id' => 11, 'embarque_id' => 1, 'valor_ta' => 1, 'taxa' => 1, 'valor_pago' => 1, 'data_pagamento' => '2026-10-01', 'capa_processo_despesa_item_id' => $itemId]);
        DB::table('resumo_resultado')->insert([['capa_processo_id' => $cover->getKey()], ['capa_processo_id' => $other->getKey()]]);

        app(DeleteCover::class)($cover);

        $this->assertModelMissing($cover);
        $this->assertModelExists($other);
        $this->assertSame(0, DB::table('capa_processo_receita_item')->where('capa_processo_id', $cover->getKey())->count());
        $this->assertSame(1, DB::table('resumo_resultado')->count());
        $this->assertNull(DB::table('ta_baixa')->value('capa_processo_despesa_item_id'));
        $released = DB::table('fin_charges')->find(1);
        $this->assertEquals([null, null, null, null, 0], [$released->ctr_valor_iof, $released->ctr_desconto, $released->ctr_juros, $released->ctr_tax_boleto, $released->ctr_fine]);
        $this->assertEquals(1, DB::table('fin_charges')->find(2)->ctr_fine);
    }
}

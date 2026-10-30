<?php

namespace Tests\Feature\Actions\AccountingCovers;

use App\Actions\AccountingCovers\CreateCover;
use App\Actions\AccountingCovers\ListPendingCoverValues;
use App\Models\CapaProcesso;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CreateCoverTest extends TestCase
{
    use LegacySchema;

    public function test_saves_the_chosen_items_flags_the_charges_and_links_the_ta_payment(): void
    {
        $this->booking(['impor_expor' => 'e']);
        $this->expense(10, 'THC');
        $this->expense(11, 'Frete');
        $this->expense(13, 'TA', refExpense: 1916);
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'created_at' => '2026-10-01', 'valor_iof' => 10, 'tax_boleto' => 2, 'fine' => 3]);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => 10, 'item_value' => 300, 'ref_currency' => 1, 'created_at' => '2026-10-01']);
        DB::table('fin_payment_orders')->insert(['id' => 1, 'ref_armador' => 1, 'created_at' => '2026-10-01', 'ref_type' => 1, 'ref_embarque' => 1, 'payment_date' => '2026-10-05', 'valor_iof' => 4]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => 1, 'ref_expense' => 11, 'ref_currency' => 1, 'item_value' => 100, 'created_at' => '2026-10-01', 'ref_bl' => 1]);
        DB::table('ta_baixa')->insert(['fin_expenses_values_id' => 13, 'embarque_id' => 1, 'valor_ta' => 1, 'taxa' => 1, 'valor_pago' => 30, 'data_pagamento' => '2026-10-02']);
        DB::table('fin_debit_notes')->insert(['ref_embarque' => 1, 'ref_agente' => 1, 'created_at' => '2026-10-01', 'created_by' => 1, 'due_to_dmf' => 100, 'handling' => 20, 'origin_comission' => 5]);
        DB::table('fin_credit_notes')->insert(['ref_embarque' => 1, 'ref_agente' => 1, 'created_at' => '2026-10-01', 'created_by' => 1, 'due_to_dmf_agent' => 15]);
        DB::table('capa_processo')->insert(['embarque_id' => 9, 'moeda_id' => 1, 'numero' => 41]);

        $cover = $this->create([
            'currency_id' => 2, 'commission' => 7, 'rate' => 5,
            'revenues' => [['id' => 10, 'value' => 290]],
            'expenses' => [['id' => 11, 'value' => 100], ['id' => 13, 'value' => 30, 'ta_payment_id' => 1]],
            'purchase_iof' => [0], 'iof' => [1], 'bank_fee' => [1], 'fine' => [1],
        ]);

        $this->assertEquals([42, 2, 5, 7, 110, '2026-10-05', 4, 10, 2, 3], [
            $cover->numero, $cover->moeda_id, $cover->valor_cotacao, $cover->comissoes, $cover->receitas,
            $cover->fresh()->payment_date->toDateString(), $cover->valor_iof_compra, $cover->valor_iof, $cover->valor_tax_boleto, $cover->valor_multa,
        ]);
        $this->assertSame([['THC/UN', 290.0]], DB::table('capa_processo_receita_item')->get(['nome', 'valor'])->map(fn ($i) => [$i->nome, (float) $i->valor])->all());
        $this->assertSame(2, DB::table('capa_processo_despesa_item')->count());
        $taItem = DB::table('capa_processo_despesa_item')->where('fin_expenses_values_id', 13)->value('capa_processo_despesa_item_id');
        $this->assertEquals($taItem, DB::table('ta_baixa')->value('capa_processo_despesa_item_id'));
        $charge = DB::table('fin_charges')->first();
        $this->assertEquals([1, 1, 1], [$charge->ctr_valor_iof, $charge->ctr_tax_boleto, $charge->ctr_fine]);
        $this->assertSame([], $this->pending()['iof']);
    }

    public function test_rejects_items_that_are_not_pending_and_saves_nothing(): void
    {
        $this->booking();
        $this->expense(10, 'THC');
        DB::table('fin_charges')->insert(['id' => 1, 'ref_type' => 1, 'ref_booking' => 1, 'created_at' => '2026-10-01']);
        DB::table('fin_charges_items')->insert(['ref_charge' => 1, 'ref_expense' => 10, 'item_value' => 300, 'ref_currency' => 1, 'created_at' => '2026-10-01']);
        $selection = ['currency_id' => 1, 'commission' => 0, 'rate' => 0, 'revenues' => [['id' => 10, 'value' => 300]]];

        $this->create($selection);

        $this->expectException(DomainException::class);
        try {
            $this->create($selection);
        } finally {
            $this->assertSame(1, DB::table('capa_processo')->count());
        }
    }

    public function test_rejects_a_charge_extra_that_was_not_offered(): void
    {
        $this->booking();

        $this->expectException(DomainException::class);
        $this->create(['currency_id' => 1, 'commission' => 0, 'rate' => 0, 'fine' => [99]]);
    }

    public function test_fiducia_discount_applies_once_per_booking_in_the_discount_currency(): void
    {
        $this->booking(['tipo_embarque' => 'm', 'cliente_id' => 5]);
        DB::table('fiducia_desconto')->insert(['pessoa_id' => 5, 'moeda_id' => 2, 'valor' => 10, 'tipo_desconto' => 'c']);
        DB::table('bl')->insert(['bl_id' => 1, 'embarque_id' => 1]);
        DB::table('container')->insert([['bl_id' => 1], ['bl_id' => 1], ['bl_id' => 1]]);

        $first = $this->create(['currency_id' => 2, 'commission' => 0, 'rate' => 5]);
        $second = $this->create(['currency_id' => 2, 'commission' => 0, 'rate' => 5]);

        $this->assertEquals([150, 0], [$first->valor_fiducia, $second->valor_fiducia]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): void
    {
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'B1'] + $attributes);
    }

    private function expense(int $valueId, string $name, int $refExpense = 0): void
    {
        DB::table('fin_expenses_unities')->insertOrIgnore(['id' => 1, 'name' => 'Unidade', 'acronym' => 'UN']);
        $refExpense = $refExpense ?: $valueId;
        DB::table('fin_expenses')->insert(['id' => $refExpense, 'name' => $name, 'ref_unity' => 1]);
        DB::table('fin_expenses_values')->insert(['id' => $valueId, 'ref_expense' => $refExpense, 'ref_embarque' => 1]);
    }

    /** @param  array<string, mixed>  $selection */
    private function create(array $selection): CapaProcesso
    {
        return app(CreateCover::class)(Embarque::findOrFail(1), $selection, 7);
    }

    /** @return array<string, mixed> */
    private function pending(): array
    {
        return app(ListPendingCoverValues::class)(Embarque::findOrFail(1));
    }
}

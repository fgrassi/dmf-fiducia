<?php

namespace Tests\Feature\Actions\AgentNotes;

use App\Actions\AgentNotes\AgentNoteLists;
use App\Actions\AgentNotes\AgentNoteStatementPdf;
use App\Models\AgenteBanco;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentNoteStatementTest extends TestCase
{
    use LegacySchema;

    public function test_statement_splits_paid_notes_between_dmf_and_agent_with_the_pdf_rules(): void
    {
        $this->seedBase();
        $exportCredit = $this->note('fin_credit_notes', 'e', ['due_to_dmf_agent' => 10, 'due_to_cnee_agent' => 5, 'due_to_dmf' => 99]);
        $importCredit = $this->note('fin_credit_notes', 'i', ['due_to_dmf' => 20, 'due_to_cnee_agent' => 3, 'due_to_dmf_agent' => 99]);
        $importDebit = $this->note('fin_debit_notes', 'i', ['due_to_dmf_agent' => 7, 'due_to_dmf' => 99]);
        $exportDebit = $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 4, 'handling' => 1, 'due_to_dmf_agent' => 99]);
        $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 50, 'status_pagamento' => 0, 'data_pagamento' => null]);
        $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 60, 'data_pagamento' => '2026-09-30']);
        $variedToAgent = $this->varied(['tipo_nota' => 1, 'impor_expor' => 'e', 'valor' => 11]);
        $courier = $this->varied(['tipo_nota' => 2, 'impor_expor' => 'i', 'shipper' => 'COURIER', 'valor' => 12]);
        $this->varied(['tipo_nota' => 2, 'impor_expor' => 'e', 'shipper' => 'COURRIER', 'valor' => 13]);

        $statement = app(AgentNoteLists::class)->statement(5, '2026-10-01', '2026-10-31');

        $this->assertEqualsCanonicalizing(["c_{$importCredit}", "d_{$exportDebit}", "v_{$courier}"], $statement['dmf']->keys()->all());
        $this->assertEqualsCanonicalizing(["c_{$exportCredit}", "d_{$importDebit}", "v_{$variedToAgent}"], $statement['agent']->keys()->all());
        $this->assertEqualsCanonicalizing([23.0, 5.0, 12.0], $statement['dmf']->pluck('valor')->map(fn ($value) => (float) $value)->all());
        $this->assertSame(['COU'], $statement['dmf']->where('tipo', 'v')->pluck('label')->all());
        $this->assertEqualsCanonicalizing([15.0, 7.0, 11.0], $statement['agent']->pluck('valor')->map(fn ($value) => (float) $value)->all());
    }

    public function test_statement_accepts_a_period_open_on_one_side(): void
    {
        $this->seedBase();
        $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 1, 'data_pagamento' => '2026-08-01']);
        $recent = $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 2, 'data_pagamento' => '2026-10-31']);

        $this->assertSame(["d_{$recent}"], app(AgentNoteLists::class)->statement(5, '2026-10-01', null)['dmf']->keys()->all());
        $this->assertCount(2, app(AgentNoteLists::class)->statement(5, null, '2026-10-31')['dmf']);
    }

    public function test_the_balance_is_due_to_the_side_with_more_value_by_the_last_rate(): void
    {
        $this->seedBase();
        $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 100]);
        $this->note('fin_credit_notes', 'e', ['due_to_dmf_agent' => 30]);
        $statement = app(AgentNoteLists::class)->statement(5, null, null);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'Dólar', 'ref_currency' => 2]);
        DB::table('fin_currencies_rates_values')->insert([
            ['rate' => 9.0, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-10-01'],
            ['rate' => 5.0, 'ref_currency_rate' => 1, 'created_by' => 1, 'rate_date' => '2026-10-02'],
        ]);

        $summary = app(AgentNoteStatementPdf::class)->summary($statement['dmf'], $statement['agent']);

        $this->assertTrue($summary['dueToDmf']);
        $this->assertSame([2 => 70.0], $summary['net']);

        $reversed = app(AgentNoteStatementPdf::class)->summary($statement['agent'], $statement['dmf']);

        $this->assertFalse($reversed['dueToDmf']);
        $this->assertSame([2 => 70.0], $reversed['net']);
    }

    public function test_the_pdf_is_rendered_with_the_chosen_account(): void
    {
        $this->seedBase();
        $this->note('fin_debit_notes', 'e', ['due_to_dmf' => 100]);
        $account = AgenteBanco::query()->create(['agente_id' => 5, 'nome_banco' => 'Banco X', 'nome_conta' => 'Agente Um LLC']);

        $output = app(AgentNoteStatementPdf::class)->render(5, null, null, $account)->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $output);
    }

    private function seedBase(): void
    {
        DB::table('pessoa')->insertOrIgnore([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 7, 'nome' => 'Cliente Sete']]);
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
        DB::table('embarque')->insertOrIgnore([
            ['embarque_id' => 10, 'booking' => 'BKG-I', 'impor_expor' => 'i', 'finalizado' => 'a', 'pessoa_id_cliente' => 7],
            ['embarque_id' => 11, 'booking' => 'BKG-E', 'impor_expor' => 'e', 'finalizado' => 'a', 'pessoa_id_cliente' => 7],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function note(string $table, string $mode, array $attributes): int
    {
        return DB::table($table)->insertGetId($attributes + [
            'ref_embarque' => $mode === 'i' ? 10 : 11, 'ref_agente' => 5, 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1,
            'moeda_id' => 2, 'status_pagamento' => 1, 'data_pagamento' => '2026-10-15',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function varied(array $attributes): int
    {
        return DB::table('fin_despesas_variadas')->insertGetId($attributes + [
            'agente_id' => 5, 'cmn_currencies_id' => 2, 'data_despesa' => '2026-10-02', 'invoice' => 'INV', 'shipper' => 'ACME',
            'status_pagamento' => 1, 'data_pagamento' => '2026-10-15',
        ]);
    }
}

<?php

namespace Tests\Feature\Actions\AgentNotes;

use App\Actions\AgentNotes\AgentNoteLists;
use App\Actions\AgentNotes\AgentNotePayments;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentNotesActionsTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('pessoa')->insert([['pessoa_id' => 5, 'nome' => 'Agente Um'], ['pessoa_id' => 7, 'nome' => 'Cliente Sete']]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'IMP', 'impor_expor' => 'i', 'finalizado' => 'a', 'pessoa_id_cliente' => 7],
            ['embarque_id' => 11, 'booking' => 'EXP', 'impor_expor' => 'e', 'finalizado' => 'a', 'pessoa_id_cliente' => 7],
            ['embarque_id' => 12, 'booking' => 'CAN', 'impor_expor' => 'e', 'finalizado' => 'c', 'pessoa_id_cliente' => 7],
        ]);
    }

    public function test_open_list_without_period_sums_credits_debits_and_varied_notes_with_the_legacy_values(): void
    {
        $this->credit(10, ['due_to_dmf' => 100, 'due_to_dmf_agent' => 999, 'due_to_cnee_agent' => 5]);
        $this->credit(11, ['due_to_dmf' => 999, 'due_to_dmf_agent' => 40, 'due_to_cnee_agent' => 2]);
        $this->debit(10, ['due_to_dmf' => 1, 'handling' => 2, 'origin_comission' => 3, 'due_to_shipper_agent' => 4, 'exw_expenses' => 5]);
        $this->varied(['valor' => 77]);

        $rows = app(AgentNoteLists::class)->open(5, null, null);

        $this->assertEqualsCanonicalizing(
            ['c' => [105.0, 42.0], 'd' => [15.0], 'v' => [77.0]],
            $rows->groupBy('tipo')->map(fn ($group) => $group->pluck('valor')->map(fn ($valor) => (float) $valor)->all())->all(),
        );
        $this->assertSame('Cliente Sete', $rows->firstWhere('tipo', 'd')['shipper']);
    }

    public function test_open_list_skips_paid_notes_other_agents_and_credits_of_cancelled_shipments(): void
    {
        $this->credit(10, ['status_pagamento' => 1]);
        $this->credit(12);
        $this->credit(10, ['ref_agente' => 6]);
        $this->debit(12, ['due_to_dmf' => 8]);

        $rows = app(AgentNoteLists::class)->open(5, null, null);

        $this->assertSame(['d'], $rows->pluck('tipo')->all());
    }

    public function test_open_list_with_period_filters_import_credits_and_debits_but_not_export_credits_and_drops_varied_notes(): void
    {
        $this->credit(10, ['created_at' => '2026-10-31 15:00:00']);
        $this->credit(10, ['created_at' => '2026-09-01 10:00:00']);
        $this->credit(11, ['created_at' => '2020-01-01 10:00:00']);
        $this->debit(10, ['created_at' => '2026-10-02 10:00:00', 'due_to_dmf' => 1]);
        $this->debit(10, ['created_at' => '2026-11-01 00:00:00', 'due_to_dmf' => 1]);
        $this->varied();

        $rows = app(AgentNoteLists::class)->open(5, '2026-10-01', '2026-10-31');

        $this->assertSame(['2020-01-01 10:00:00', '2026-10-02 10:00:00', '2026-10-31 15:00:00'], $rows->pluck('created_at')->all());
        $this->assertNotContains('v', $rows->pluck('tipo')->all());
    }

    public function test_period_with_only_one_date_is_ignored(): void
    {
        $this->debit(10, ['created_at' => '2020-01-01 10:00:00', 'due_to_dmf' => 1]);
        $this->varied();

        $this->assertCount(2, app(AgentNoteLists::class)->open(5, '2026-10-01', null));
    }

    public function test_paid_list_filters_by_payment_date_with_one_side_optional_using_the_export_credit_value_for_every_credit(): void
    {
        $this->credit(10, ['status_pagamento' => 1, 'data_pagamento' => '2026-10-05', 'due_to_dmf' => 999, 'due_to_dmf_agent' => 30, 'due_to_cnee_agent' => 3]);
        $this->credit(10, ['status_pagamento' => 1, 'data_pagamento' => '2026-09-05']);
        $this->debit(11, ['status_pagamento' => 1, 'data_pagamento' => '2026-10-31', 'due_to_dmf' => 6]);
        $this->varied(['status_pagamento' => 1, 'data_pagamento' => '2026-10-10', 'valor' => 9]);
        $this->varied(['status_pagamento' => 0, 'data_pagamento' => '2026-10-10']);

        $this->assertCount(4, app(AgentNoteLists::class)->paid(5, null, null));
        $this->assertCount(3, app(AgentNoteLists::class)->paid(5, '2026-10-01', null));

        $rows = app(AgentNoteLists::class)->paid(5, '2026-10-01', '2026-10-31');

        $this->assertEqualsCanonicalizing([33.0, 6.0, 9.0], $rows->pluck('valor')->map(fn ($valor) => (float) $valor)->all());
    }

    public function test_settle_marks_credit_debit_and_varied_notes_and_ignores_missing_ones(): void
    {
        $credit = $this->credit(10);
        $debit = $this->debit(10);
        $varied = $this->varied();

        app(AgentNotePayments::class)->settle(["c_{$credit}", "d_{$debit}", "v_{$varied}", 'c_9999', 'x_1', 'lixo'], '2026-10-20');

        foreach (['fin_credit_notes' => ['id', $credit], 'fin_debit_notes' => ['id', $debit], 'fin_despesas_variadas' => ['fin_despesas_variadas_id', $varied]] as $table => [$key, $id]) {
            $row = DB::table($table)->where($key, $id)->first();
            $this->assertSame([1, '2026-10-20'], [(int) $row->status_pagamento, $row->data_pagamento], $table);
        }
    }

    public function test_settle_refuses_an_empty_or_invalid_date(): void
    {
        $credit = $this->credit(10);

        foreach ([null, '', '31/02/2026'] as $date) {
            try {
                app(AgentNotePayments::class)->settle(["c_{$credit}"], $date);
                $this->fail('A baixa sem data válida deveria ser recusada.');
            } catch (DomainException) {
            }
        }

        $this->assertSame(0, (int) DB::table('fin_credit_notes')->value('status_pagamento'));
    }

    public function test_reopen_clears_the_payment_status_and_date(): void
    {
        $debit = $this->debit(10, ['status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);
        $varied = $this->varied(['status_pagamento' => 1, 'data_pagamento' => '2026-10-05']);

        app(AgentNotePayments::class)->reopen(["d_{$debit}", "v_{$varied}"]);

        $this->assertDatabaseHas('fin_debit_notes', ['id' => $debit, 'status_pagamento' => 0, 'data_pagamento' => null]);
        $this->assertDatabaseHas('fin_despesas_variadas', ['fin_despesas_variadas_id' => $varied, 'status_pagamento' => 0, 'data_pagamento' => null]);
    }

    /** @param array<string, mixed> $attributes */
    private function credit(int $booking, array $attributes = []): int
    {
        return DB::table('fin_credit_notes')->insertGetId($attributes + [
            'ref_embarque' => $booking, 'ref_agente' => 5, 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1, 'moeda_id' => 2,
            'due_to_dmf' => 1, 'due_to_dmf_agent' => 1, 'due_to_cnee_agent' => 1, 'status_pagamento' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function debit(int $booking, array $attributes = []): int
    {
        return DB::table('fin_debit_notes')->insertGetId($attributes + [
            'ref_embarque' => $booking, 'ref_agente' => 5, 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1, 'moeda_id' => 2, 'status_pagamento' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function varied(array $attributes = []): int
    {
        return DB::table('fin_despesas_variadas')->insertGetId($attributes + [
            'agente_id' => 5, 'cmn_currencies_id' => 2, 'data_despesa' => '2026-10-10', 'booking' => 'BKG', 'valor' => 10, 'tipo_nota' => '2', 'status_pagamento' => 0,
        ]);
    }
}

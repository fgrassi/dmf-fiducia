<?php

namespace Tests\Feature\Actions\AgentNotes;

use App\Actions\AgentNotes\OverdueNotesNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class OverdueNotesNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('network')->insert(['network_id' => 1, 'nome' => 'WCA']);
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Agente Alfa', 'network_id' => 1],
            ['pessoa_id' => 2, 'nome' => 'Agente Beta', 'network_id' => null],
            ['pessoa_id' => 3, 'nome' => 'Agente Devedor', 'network_id' => null],
            ['pessoa_id' => 3198, 'nome' => 'Agente Ignorado', 'network_id' => null],
        ]);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'EXP10', 'impor_expor' => 'e'],
            ['embarque_id' => 11, 'booking' => 'IMP11', 'impor_expor' => 'i'],
        ]);
    }

    public function test_lists_old_open_export_notes_by_agent_with_credit_negative_and_grand_total(): void
    {
        $this->debit('DN-1', agent: 1, due: 100);
        $this->credit('CN-1', agent: 1, due: 30);
        $this->debit('DN-2', agent: 2, due: 50);
        $this->debit('DN-NEW', agent: 1, due: 999, createdAt: '2026-10-20');
        $this->debit('DN-PAID', agent: 1, due: 999, paid: 1);
        $this->debit('DN-IMP', agent: 1, due: 999, booking: 11);
        $this->debit('DN-IGN', agent: 3198, due: 999);

        $sent = app(OverdueNotesNotice::class)();

        $this->assertSame(3, $sent);
        $this->assertSame(['[FINANCEIRO] Relação de Notas de Debito/Crédito emitidas +30 dias'], $this->sentSubjects()->all());
        $this->assertSame(['marcia@dmflog.com.br', 'financeiro@dmflog.com.br', 'chamado@dmflog.com.br'], $this->sentRecipients());

        $html = $this->sentHtml();
        $this->assertStringContainsString("<td>Agente Alfa</td><td>DN-1</td><td align='right'>01/10/2026</td><td align='right'>US$ 100,00</td><td>WCA</td>", $html);
        $this->assertStringContainsString("<td>CN-1</td><td align='right'>01/10/2026</td><td align='right'>US$ -30,00</td>", $html);
        $this->assertStringContainsString("<td>TOTAL</td><td /><td /><td align='right'>70,00</td>", $html);
        $this->assertStringContainsString("<td>TOTAL GERAL</td><td /><td /><td align='right'>120,00</td>", $html);
        foreach (['DN-NEW', 'DN-PAID', 'DN-IMP', 'DN-IGN'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $html);
        }
    }

    public function test_leaves_out_agents_whose_open_balance_is_negative(): void
    {
        $this->debit('DN-OK', agent: 1, due: 100);
        $this->debit('DN-DEV', agent: 3, due: 10);
        $this->credit('CN-DEV', agent: 3, due: 500);
        $this->credit('CN-DEV2', agent: 3, due: 600);

        $sent = app(OverdueNotesNotice::class)();

        $this->assertSame(1, $sent);
        $this->assertStringContainsString('DN-OK', $this->sentHtml());
        $this->assertStringNotContainsString('Agente Devedor', $this->sentHtml());
    }

    public function test_still_emails_the_team_when_there_is_no_overdue_note(): void
    {
        $sent = app(OverdueNotesNotice::class)();

        $this->assertSame(0, $sent);
        $this->assertSame('Nenhuma nota encontrada', $this->sentHtml());
    }

    private function debit(string $name, int $agent, float $due, string $createdAt = '2026-10-01', int $paid = 0, int $booking = 10): void
    {
        DB::table('fin_debit_notes')->insert([
            'ref_embarque' => $booking, 'ref_agente' => $agent, 'created_by' => 1, 'created_at' => $createdAt, 'name' => $name,
            'due_to_dmf' => $due, 'moeda_id' => 1, 'status_pagamento' => $paid,
        ]);
    }

    private function credit(string $name, int $agent, float $due): void
    {
        DB::table('fin_credit_notes')->insert([
            'ref_embarque' => 10, 'ref_agente' => $agent, 'created_by' => 1, 'created_at' => '2026-10-01', 'name' => $name,
            'due_to_dmf_agent' => $due, 'due_to_cnee_agent' => 0, 'moeda_id' => 1, 'status_pagamento' => 0,
        ]);
    }
}

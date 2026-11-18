<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\NotesControlMonthlyReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NotesControlMonthlyReportTest extends TestCase
{
    use LegacySchema;

    public function test_on_the_first_lists_bookings_whose_note_control_changed_last_month(): void
    {
        $this->travelTo('2026-11-01 08:00');
        DB::table('embarque')->insert([
            ['embarque_id' => 1, 'booking' => 'BKG1', 'finalizado' => 'a', 'controle_credit' => '2026-10-31 15:30:00', 'controle_debit' => '2026-10-05 09:00:00'],
            ['embarque_id' => 2, 'booking' => 'BKG2', 'finalizado' => 'a', 'controle_credit' => null, 'controle_debit' => '2026-09-30 23:00:00'],
            ['embarque_id' => 3, 'booking' => 'BKG3', 'finalizado' => 'c', 'controle_credit' => null, 'controle_debit' => '2026-10-10 10:00:00'],
            ['embarque_id' => 4, 'booking' => 'BKG4', 'finalizado' => 'e', 'controle_credit' => '2026-10-10 10:00:00', 'controle_debit' => null],
        ]);

        $sent = app(NotesControlMonthlyReport::class)();

        $this->assertSame(1, $sent);
        $this->assertSame(['Relatório Controle de Notas -  OUTUBRO/2026'], $this->sentSubjects()->all());
        $this->assertSame(['marcia@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('no período de 01/10/2026 a 31/10/2026.', $this->sentHtml());
        $this->assertStringContainsString('<td>BKG1</td><td><ul><li>CN - 31/10/2026 15:30</li><li>DN - 05/10/2026 09:00</li></ul></td>', $this->sentHtml());
        foreach (['BKG2', 'BKG3', 'BKG4'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $this->sentHtml());
        }
    }

    public function test_on_the_first_still_emails_when_nothing_changed(): void
    {
        $this->travelTo('2026-01-01 08:00');

        $sent = app(NotesControlMonthlyReport::class)();

        $this->assertSame(0, $sent);
        $this->assertSame(['Relatório Controle de Notas -  DEZEMBRO/2025'], $this->sentSubjects()->all());
        $this->assertSame('Nenhuma alteração realizada.', $this->sentHtml());
    }

    public function test_sends_nothing_on_other_days(): void
    {
        $this->travelTo('2026-11-02 08:00');

        $this->assertSame(0, app(NotesControlMonthlyReport::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }
}

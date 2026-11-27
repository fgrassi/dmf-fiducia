<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\UnreviewedExpensesNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class UnreviewedExpensesNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente <A>']);
    }

    public function test_lists_unreviewed_maritime_imports_arriving_within_three_days_and_emails_the_team(): void
    {
        $this->booking('LIMIT', ['chegada_prevista' => '2026-11-12']);
        $this->booking('LATE', ['chegada_prevista' => '2026-10-01', 'cliente_id' => null]);
        $this->booking('FUTURE', ['chegada_prevista' => '2026-11-13']);
        $this->booking('NODATE', ['chegada_prevista' => null]);
        $this->booking('ZERO', ['chegada_prevista' => '0000-00-00']);
        $this->booking('REVIEWED', ['revisao_despesas' => 's']);
        $this->booking('CANCELED', ['finalizado' => 'c']);
        $this->booking('EXPORT', ['impor_expor' => 'e']);
        $this->booking('AIR', ['tipo_embarque' => 'a']);

        $this->assertSame(2, app(UnreviewedExpensesNotice::class)());
        $this->assertSame(['[Aviso] Embarques sem revisão de despesas'], $this->sentSubjects()->all());
        $this->assertSame(
            ['imp2@dmflog.com.br', 'imp3@dmflog.com.br', 'imp4@dmflog.com.br', 'imp5@dmflog.com.br', 'financeiro@dmflog.com.br'],
            $this->sentRecipients(),
        );
        $this->assertStringContainsString(
            '<tr><td>LATE</td><td></td><td>01/10/2026</td></tr><tr><td>LIMIT</td><td>Cliente &lt;A&gt;</td><td>12/11/2026</td></tr></table>',
            $this->sentHtml(),
        );
    }

    public function test_sends_nothing_when_every_booking_is_reviewed(): void
    {
        $this->booking('REVIEWED', ['revisao_despesas' => 's']);

        $this->assertSame(0, app(UnreviewedExpensesNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(string $booking, array $attributes): void
    {
        DB::table('embarque')->insert([...[
            'booking' => $booking, 'cliente_id' => 1, 'impor_expor' => 'i', 'tipo_embarque' => 'm',
            'revisao_despesas' => 'n', 'finalizado' => 'n', 'chegada_prevista' => '2026-11-10',
        ], ...$attributes]);
    }
}

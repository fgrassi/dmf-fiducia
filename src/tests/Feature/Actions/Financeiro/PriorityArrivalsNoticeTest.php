<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\PriorityArrivalsNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PriorityArrivalsNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente Sul', 'cnpj' => '11.222.333/0001-44']);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
    }

    public function test_sends_one_email_for_arrivals_in_five_days_and_another_for_two_days(): void
    {
        $this->booking(10, 'BKG5', '2026-11-14');
        $this->booking(11, 'BKG2', '2026-11-11');
        $this->booking(12, 'BKG3', '2026-11-12');
        DB::table('mercadoria')->insert(['embarque_id' => 10, 'quantidade' => 2, 'tipo_container_id' => 1]);

        $sent = app(PriorityArrivalsNotice::class)();

        $this->assertSame(2, $sent);
        $this->assertSame([
            '[Aviso] Embarques Prioritários ETA próximo (+5 dias)',
            '[Aviso] Embarques Prioritários ETA próximo (+2 dias)',
        ], $this->sentSubjects()->all());
        $this->assertStringContainsString('<td>BKG5</td><td>Cliente Sul (11.222.333/0001-44)</td><td>14/11/2026</td><td><ul><li>2x40HC</li></ul></td>', $this->sentHtml());
        $this->assertStringContainsString('<td>BKG2</td>', $this->sentHtml());
        $this->assertStringNotContainsString('BKG3', $this->sentHtml());
    }

    public function test_ignores_non_priority_and_cancelled_bookings(): void
    {
        $this->booking(10, 'NORMAL', '2026-11-14', priority: 0);
        $this->booking(11, 'CANCELADA', '2026-11-14', status: 'c');

        $this->assertSame(0, app(PriorityArrivalsNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(int $id, string $booking, string $eta, int $priority = 1, string $status = 'a'): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id, 'booking' => $booking, 'chegada_prevista' => $eta, 'flag_prioritario' => $priority, 'finalizado' => $status, 'cliente_id' => 1,
        ]);
    }
}

<?php

namespace Tests\Feature\Actions\Cobranca\Notices;

use App\Actions\Cobranca\Notices\PendingTakersNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PendingTakersNoticeTest extends TestCase
{
    use LegacySchema;

    public function test_emails_it_the_takers_with_pending_registry_in_the_next_five_days(): void
    {
        $this->travelTo('2026-11-02 08:00:00');
        $this->booking('2026-11-07');
        $this->booking('2026-11-09');

        $listed = app(PendingTakersNotice::class)();

        $this->assertSame(1, $listed);
        $this->assertSame(['DMF Logistics: tomadores com pendência de cadastro - 02/11/2026'], $this->sentSubjects()->all());
    }

    public function test_sends_nothing_when_no_taker_is_pending(): void
    {
        $this->travelTo('2026-11-02 08:00:00');
        $this->booking('2026-11-20');

        $listed = app(PendingTakersNotice::class)();

        $this->assertSame(0, $listed);
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function booking(string $departure): void
    {
        $id = DB::table('embarque')->insertGetId(['impor_expor' => 'e', 'saida_prevista' => $departure, 'finalizado' => 'a']);
        DB::table('bl')->insert(['embarque_id' => $id, 'shipper' => 'CNPJ 11.222.333/0001-81']);
    }
}

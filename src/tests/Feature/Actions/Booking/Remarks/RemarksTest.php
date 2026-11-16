<?php

namespace Tests\Feature\Actions\Booking\Remarks;

use App\Actions\Booking\ReleaseShipment;
use App\Actions\Booking\Remarks\AddRemark;
use App\Actions\Booking\Remarks\RemoveRemark;
use App\Actions\Booking\Remarks\SaveBookingNotes;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class RemarksTest extends TestCase
{
    use LegacySchema;

    public function test_adds_and_removes_a_remark(): void
    {
        $this->travelTo('2026-11-16 09:30');

        $remark = app(AddRemark::class)(10, 'Ligar para o cliente', 7);

        $this->assertSame(['Ligar para o cliente', 10, 7, '2026-11-16 09:30:00'], [$remark->mensagem, $remark->embarque_id, $remark->created_by, $remark->getRawOriginal('created_at')]);

        app(RemoveRemark::class)($remark);

        $this->assertSame(0, DB::table('observacoes_embarque')->count());
    }

    public function test_saves_the_booking_notes_and_records_who_changed_them(): void
    {
        $booking = $this->booking(['embarque_obs' => 'antiga']);

        app(SaveBookingNotes::class)($booking, "nova\r\nlinha", 7);

        $this->assertSame("nova\nlinha", $booking->fresh()->embarque_obs);
        $row = DB::table('rastreabilidade_alteracao')->first();
        $this->assertSame(['embarque', 'embarque_obs', 10, 'antiga', "nova\nlinha", 7], [$row->tabela, $row->campo, (int) $row->tabela_id, $row->valor_antigo, $row->valor_novo, (int) $row->created_by]);
    }

    public function test_unchanged_notes_leave_no_history_and_blank_notes_become_null(): void
    {
        $booking = $this->booking(['embarque_obs' => 'igual']);

        app(SaveBookingNotes::class)($booking, 'igual', 7);
        $this->assertSame(0, DB::table('rastreabilidade_alteracao')->count());

        app(SaveBookingNotes::class)($booking, '', 7);
        $this->assertNull($booking->fresh()->embarque_obs);
        $this->assertSame(1, DB::table('rastreabilidade_alteracao')->count());
    }

    public function test_releasing_a_shipment_only_touches_its_pending_release(): void
    {
        $this->travelTo('2026-11-16 09:30');
        DB::table('liberacao_embarque')->insert([['embarque_id' => 10, 'status' => 0], ['embarque_id' => 11, 'status' => 0]]);

        app(ReleaseShipment::class)(10, 7);

        $this->assertSame([1, 7, '2026-11-16 09:30:00'], [(int) DB::table('liberacao_embarque')->where('embarque_id', 10)->value('status'), (int) DB::table('liberacao_embarque')->where('embarque_id', 10)->value('updated_by'), DB::table('liberacao_embarque')->where('embarque_id', 10)->value('updated_at')]);
        $this->assertSame(0, (int) DB::table('liberacao_embarque')->where('embarque_id', 11)->value('status'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['embarque_id' => 10, 'booking' => 'BKG1']));
    }
}

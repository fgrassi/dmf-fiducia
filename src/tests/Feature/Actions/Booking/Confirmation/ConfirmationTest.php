<?php

namespace Tests\Feature\Actions\Booking\Confirmation;

use App\Actions\Booking\Confirmation\ConfirmArrival;
use App\Actions\Booking\Confirmation\ConfirmDeparture;
use App\Actions\Booking\Confirmation\ExpenseMatrixCheck;
use App\Actions\Booking\Confirmation\RemoveArrival;
use App\Actions\Booking\Confirmation\RemoveDeparture;
use App\Models\Embarque;
use App\Models\Transborda;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ConfirmationTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'ACME EXPORT'], ['pessoa_id' => 2, 'nome' => 'ACME IMPORT']]);
        DB::table('usuario')->insert([
            ['usuario_id' => 7, 'nome' => 'Ana', 'login' => 'ana', 'email' => 'ana@dmflog.com.br', 'status' => 1, 'receber_avisos' => 'n'],
            ['usuario_id' => 20, 'nome' => 'Cliente', 'login' => 'c', 'email' => 'cliente@acme.com', 'status' => 1, 'receber_avisos' => 's'],
        ]);
        DB::table('frase_transborda')->insert([
            ['id' => 82, 'valor' => 'Embarque', 'frase' => 'x', 'categoria_id' => 10],
            ['id' => 33, 'valor' => 'Chegada', 'frase' => 'x', 'categoria_id' => 11],
            ['id' => 113, 'valor' => 'Conclusão', 'frase' => 'x', 'categoria_id' => 11],
        ]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos'], ['porto_id' => 2, 'nome' => 'Shanghai']]);
        DB::table('navio')->insert(['navio_id' => 9, 'nome' => 'MSC ANNA']);
        DB::table('armador')->insert(['armador_id' => 5, 'nome' => 'MSC', 'invoice_expo' => 1, 'invoice_impo' => 0]);
        DB::table('cmn_armador_porto')->insert(['id' => 1, 'ref_armador' => 5, 'ref_porto' => 1]);
    }

    public function test_export_departure_saves_the_message_the_effective_date_and_the_pendencies_once(): void
    {
        DB::table('pessoa')->where('pessoa_id', 1)->update(['ctr_envio_valores' => 's']);
        DB::table('controle_pa')->insert(['embarque_id' => 1, 'flag_fi_dmf' => 1]);
        DB::table('embarque_contato_cliente')->insert(['embarque_id' => 1, 'usuario_id' => 20]);
        DB::table('embarque_favorito')->insert(['embarque_id' => 1, 'usuario_id' => 7]);
        $booking = $this->booking(['loading_type' => 'f', 'saida_prevista' => '2026-11-01', 'chegada_prevista' => '2026-11-31 00:00:00', 'vg' => 'V1', 'navio_id' => 9]);
        DB::table('bl')->insert(['embarque_id' => 1, 'mbl_number' => 'MBL1']);

        $confirm = app(ConfirmDeparture::class);
        $tracking = $confirm($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-10'], 7);
        $confirm($booking->fresh(), ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-11'], 7, $tracking);

        $this->assertSame('2026-11-11', DB::table('embarque')->value('saida_efetiva'));
        $this->assertSame(1, DB::table('transborda')->count());
        $this->assertStringContainsString('Container(s) embarcado(s) no navio MSC ANNA vg.V1 no dia 11/Nov/2026 em Santos', $tracking->fresh()->descr);
        $this->assertStringContainsString('EXPECTED', $tracking->fresh()->descr);
        $this->assertSame(1, DB::table('departure_confirmation')->count());
        $this->assertSame(1, DB::table('controle_free_hand')->count());
        $this->assertSame(1, DB::table('controle_envio_valores')->count());
        $this->assertSame(1, DB::table('controle_invoice_master')->where('mbl_number', 'MBL1')->count());
        $this->assertSame(1, DB::table('cmn_messages_recipients')->count());
        $this->assertSame(2, (int) DB::table('controle_pa')->value('flag_fi_dmf'));
        $this->assertTrue($confirm->sent);
    }

    public function test_departure_is_refused_for_a_future_date_and_when_the_purchase_differs_from_the_carrier_matrix(): void
    {
        $booking = $this->booking();

        try {
            app(ConfirmDeparture::class)($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => now()->addDays(2)->toDateString()], 7);
            $this->fail('A data futura devia ser recusada.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('maior que o dia de hoje', $e->getMessage());
        }

        DB::table('fin_expenses')->insert(['id' => 30, 'name' => 'THC']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => 1, 'ref_expense' => 30, 'buying_rate' => 100, 'ref_currency_br' => 1, 'billing' => 'p']);
        DB::table('fin_expenses_matrix')->insert(['ref_armador_porto' => 1, 'ref_expense' => 30, 'type' => 'e', 'expense_value' => 120, 'imo_value' => 0, 'created_by' => 1, 'ref_currency' => 1, 'billing' => 'p', 'due_from' => '2026-01-01']);

        try {
            app(ConfirmDeparture::class)($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-10'], 7);
            $this->fail('A matriz divergente devia recusar.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('THC: matriz 120', $e->getMessage());
        }

        $this->assertSame(0, DB::table('transborda')->count());
        $this->assertNull(DB::table('embarque')->value('saida_efetiva'));
    }

    public function test_the_matrix_check_skips_a_null_purchase_but_counts_a_zero_one_like_the_legacy(): void
    {
        $booking = $this->booking();
        DB::table('fin_expenses')->insert(['id' => 30, 'name' => 'THC']);
        DB::table('fin_expenses_matrix')->insert(['ref_armador_porto' => 1, 'ref_expense' => 30, 'type' => 'e', 'expense_value' => 120, 'imo_value' => 0, 'created_by' => 1, 'ref_currency' => 1, 'billing' => 'p', 'due_from' => '2026-01-01']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => 1, 'ref_expense' => 30, 'buying_rate' => null, 'ref_currency_br' => 1, 'billing' => 'p']);

        $this->assertTrue(app(ExpenseMatrixCheck::class)->forDeparture($booking, '2026-11-10')['ok']);

        DB::table('fin_expenses_values')->update(['buying_rate' => 0]);

        $this->assertFalse(app(ExpenseMatrixCheck::class)->forDeparture($booking, '2026-11-10')['ok']);
    }

    public function test_us_export_without_nra_documents_cannot_be_confirmed(): void
    {
        DB::table('paises')->insert(['paises_id' => 4, 'nome' => 'Estados Unidos']);
        DB::table('porto')->where('porto_id', 2)->update(['ref_pais' => 4]);
        $booking = $this->booking(['tipo_fechamento' => 'o', 'porto_destino_id' => 2, 'bl_direto' => 0, 'tipo_embarque' => 'a']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('NRA não efetivado');

        app(ConfirmDeparture::class)($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-10'], 7);
    }

    public function test_import_departure_opens_the_release_pendency_and_warns_the_import_team(): void
    {
        DB::table('pessoa')->where('pessoa_id', 2)->update(['ctr_liberacao' => 's']);
        $booking = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'a']);

        app(ConfirmDeparture::class)($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-10'], 7);

        $this->assertSame(1, DB::table('liberacao_embarque')->count());
        $mail = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Nova pendência de Liberação de Embarque - IMP1', $mail->getSubject());
        $this->assertSame(0, DB::table('controle_invoice_master')->count());
    }

    public function test_removing_the_only_departure_message_undoes_the_confirmation(): void
    {
        $booking = $this->booking(['loading_type' => 'f']);
        $first = app(ConfirmDeparture::class)($booking, ['data_reg' => '2026-11-10', 'saida_efetiva' => '2026-11-10'], 7);
        $second = app(ConfirmDeparture::class)($booking->fresh(), ['data_reg' => '2026-11-12', 'saida_efetiva' => '2026-11-10'], 7);

        app(RemoveDeparture::class)($booking->fresh(), $second);
        $this->assertSame(1, DB::table('departure_confirmation')->count());

        app(RemoveDeparture::class)($booking->fresh(), $first);
        $this->assertSame(0, DB::table('departure_confirmation')->count());
        $this->assertSame(0, DB::table('controle_free_hand')->count());
        $this->assertNull(DB::table('embarque')->value('saida_efetiva'));
    }

    public function test_import_arrival_finalizes_the_booking_and_updates_the_demurrage_and_warns_the_documentation(): void
    {
        DB::table('embarque_favorito')->insert(['embarque_id' => 1, 'usuario_id' => 7]);
        DB::table('fin_demurrage')->insert(['ref_embarque' => 1, 'container' => 'C1', 'created_at' => '2026-01-01', 'created_by' => 1]);
        $booking = $this->booking(['impor_expor' => 'i', 'free_time' => 10, 'porto_destino_id' => 2, 'data_ce_mercante' => '2026-11-10 08:00:00']);

        $tracking = app(ConfirmArrival::class)($booking, ['data_reg' => '2026-11-10', 'dtePrevista' => '2026-11-10 14:30:00', 'kind' => 0, 'vg' => 'V9', 'navio_id_destino' => 9], 7);

        $this->assertSame('s', DB::table('embarque')->value('finalizado'));
        $this->assertSame('2026-11-10 14:30:00', DB::table('embarque')->value('chegada_efetiva'));
        $this->assertSame(9, (int) DB::table('embarque')->value('navio_id_destino'));
        $this->assertSame(33, (int) $tracking->ref_frase_transborda);
        $this->assertStringContainsString('O navio MSC ANNA vg. V9 iniciou operação no porto de Shanghai', $tracking->descr);
        $this->assertSame(10, (int) DB::table('fin_demurrage')->value('free_time'));
        $this->assertSame('2026-11-10 14:30:00', DB::table('fin_demurrage')->value('data_retirada'));
        $this->assertSame(1, DB::table('arrival_confirmation')->count());
        $this->assertSame(1, DB::table('cmn_messages_recipients')->count());
        $subjects = Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage()->getSubject());
        $this->assertContains('AVISO: Desconsolidação inferior à 3 dias da atracação do navio', $subjects);
    }

    public function test_arrival_needs_the_voyage_and_a_non_future_date(): void
    {
        $booking = $this->booking(['impor_expor' => 'i']);

        foreach ([['dtePrevista' => '2026-11-10 10:00:00', 'kind' => 0, 'vg' => '', 'msg' => 'VG'], ['dtePrevista' => now()->addDays(3)->toDateTimeString(), 'kind' => 2, 'msg' => 'maior que o dia de hoje']] as $case) {
            try {
                app(ConfirmArrival::class)($booking, ['data_reg' => '2026-11-10'] + $case, 7);
                $this->fail('Devia recusar.');
            } catch (DomainException $e) {
                $this->assertStringContainsString($case['msg'], $e->getMessage());
            }
        }

        $this->assertSame(0, DB::table('transborda')->count());
    }

    public function test_removing_the_only_import_arrival_reopens_the_booking_and_logs_it(): void
    {
        $booking = $this->booking(['impor_expor' => 'i']);
        $tracking = app(ConfirmArrival::class)($booking, ['data_reg' => '2026-11-10', 'dtePrevista' => '2026-11-10 10:00:00', 'kind' => 4], 7);

        app(RemoveArrival::class)($booking->fresh(), Transborda::find($tracking->getKey()), 7);

        $this->assertSame('n', DB::table('embarque')->value('finalizado'));
        $this->assertNull(DB::table('embarque')->value('chegada_efetiva'));
        $this->assertSame(0, DB::table('arrival_confirmation')->count());
        $this->assertStringContainsString("o status foi definido como 'Open'", DB::table('rastreamento')->value('historico'));
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + [
            'booking' => 'IMP1', 'invoice' => 'INV1', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'armador_id' => 5,
            'pessoa_id_cliente' => 1, 'cliente_id' => 1, 'pessoa_id_importador' => 2, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'data_topo' => '2026-11-01',
        ]));
    }
}

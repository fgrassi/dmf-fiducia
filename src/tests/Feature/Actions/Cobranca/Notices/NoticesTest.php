<?php

namespace Tests\Feature\Actions\Cobranca\Notices;

use App\Actions\Cobranca\Notices\ExpenseDueNotice;
use App\Actions\Cobranca\Notices\HapagPendingNotice;
use App\Actions\Cobranca\Notices\OpenChargeReminders;
use App\Actions\Cobranca\Notices\OpenImportChargesNotice;
use App\Actions\Cobranca\Notices\ReleaseList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NoticesTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 08:00');
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Cliente A']);
    }

    public function test_reminds_normal_charges_from_day_7_and_extra_charges_only_on_day_7(): void
    {
        $this->booking(10);
        $this->charge(10, '2026-10-20', type: 1);
        $this->charge(10, '2026-10-01', type: 1);
        $this->booking(11);
        $this->charge(11, '2026-10-19', type: 2);
        $this->booking(12);
        $this->charge(12, '2026-10-21', type: 1);

        $this->assertSame(1, app(OpenChargeReminders::class)());
        $this->assertSame(['[Aviso] Reserva BKG10 está com cobrança(s) há mais de 7 dias em aberto'], $this->sentSubjects()->all());
    }

    public function test_reminder_skips_clients_with_boleto_and_soprano_before_arrival(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 2164, 'nome' => 'Soprano']);
        DB::table('fin_clients_boleto')->insert(['ref_client' => 1]);
        $this->booking(10);
        $this->charge(10, '2026-10-20');
        $this->booking(11, cliente: 2164);
        $this->charge(11, '2026-10-20');

        $this->assertSame(0, app(OpenChargeReminders::class)());
    }

    public function test_import_notice_lists_every_charge_of_day_7(): void
    {
        $this->booking(10, imporExpor: 'i');
        $this->charge(10, '2026-10-20');
        $this->booking(11, imporExpor: 'i');
        $this->charge(11, '2026-10-20');

        $this->assertSame(2, app(OpenImportChargesNotice::class)());
        $this->assertStringContainsString('BKG11', $this->lastBody());
        $this->assertStringContainsString('BKG10', $this->lastBody());
    }

    public function test_hapag_notice_clears_bookings_without_pending_expenses(): void
    {
        foreach ([10, 11] as $id) {
            $this->booking($id, armador: 19, extra: ['ctr_desp' => 1, 'finalizado' => 'n']);
            DB::table('departure_confirmation')->insert(['ref_embarque' => $id, 'created_by' => 1, 'created_at' => '2026-10-27']);
        }
        DB::table('fin_expenses_values')->insert(['id' => 1, 'ref_embarque' => 11, 'billing' => 'p', 'selling_rate' => 50]);

        $this->assertSame(1, app(HapagPendingNotice::class)());
        $this->assertSame(0, (int) DB::table('embarque')->where('embarque_id', 10)->value('ctr_desp'));
        $this->assertStringContainsString('BKG11', $this->lastBody());
    }

    public function test_release_list_marks_paid_charges_as_listed(): void
    {
        $this->booking(10, imporExpor: 'i');
        $id = $this->charge(10, '2026-10-20', status: 1);

        $this->assertSame(1, app(ReleaseList::class)());
        $this->assertSame(1, (int) DB::table('fin_charges')->where('id', $id)->value('status_envio'));
        $this->assertSame(0, app(ReleaseList::class)());
        $this->assertStringContainsString('Não há nenhuma reserva para ser liberada.', $this->lastBody());
    }

    public function test_expense_due_on_day_beyond_month_end_falls_on_last_day(): void
    {
        $this->travelTo('2027-02-25');
        DB::table('fornecedor')->insert(['fornecedor_id' => 1, 'nome' => 'Fornecedor']);
        DB::table('categoria_despesa')->insert(['categoria_despesa_id' => 1, 'nome' => 'Aluguel']);
        DB::table('despesa_vencimento')->insert([
            ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 31, 'descricao' => 'dia 31'],
            ['fornecedor_id' => 1, 'categoria_despesa_id' => 1, 'dia_vencimento' => 15, 'descricao' => 'dia 15'],
        ]);

        $this->assertSame(1, app(ExpenseDueNotice::class)());
        $this->assertSame(['[Aviso] Vencimento de despesas em 3 dias (28/02/2027)'], $this->sentSubjects()->all());
    }

    /** @param  array<string, mixed>  $extra */
    private function booking(int $id, string $imporExpor = 'e', int $cliente = 1, int $armador = 1, array $extra = []): void
    {
        DB::table('embarque')->insert([
            'embarque_id' => $id,
            'booking' => "BKG{$id}",
            'tipo_embarque' => 'm',
            'impor_expor' => $imporExpor,
            'cliente_id' => $cliente,
            'armador_id' => $armador,
            ...$extra,
        ]);
    }

    private function charge(int $booking, string $createdAt, int $type = 1, int $status = 0): int
    {
        return DB::table('fin_charges')->insertGetId([
            'ref_type' => $type,
            'ref_booking' => $booking,
            'status' => $status,
            'status_envio' => 0,
            'total_value' => 100,
            'created_at' => $createdAt,
        ]);
    }

    private function lastBody(): string
    {
        /** @var SentMessage $message */
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last();

        return (string) $message->getOriginalMessage()->getHtmlBody();
    }
}

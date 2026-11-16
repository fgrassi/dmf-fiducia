<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\ChangeStatus;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChangeStatusTest extends TestCase
{
    use LegacySchema;

    public function test_cancels_and_logs_the_justification(): void
    {
        $booking = $this->booking();

        app(ChangeStatus::class)($booking, 'c', 'Cliente desistiu', 7);

        $this->assertSame('c', $booking->fresh()->finalizado);
        $this->assertSame(['Embarque Cancelado', 'Cliente desistiu', 'c', 7], $this->logged());
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_cancelling_an_export_with_insurance_warns_the_export_team(): void
    {
        $booking = $this->booking(['impor_expor' => 'e']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_expense' => 79, 'selling_rate' => 50]);

        app(ChangeStatus::class)($booking, 'c', 'Sem carga', 7);

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('AVISO! Cancelamento de Reserva com Seguro', $message->getSubject());
        $this->assertStringContainsString('exportacao@dmflog.com.br', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('Sem carga', $message->getHtmlBody());
    }

    public function test_insurance_with_zero_sale_does_not_warn(): void
    {
        $booking = $this->booking(['impor_expor' => 'e']);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $booking->getKey(), 'ref_expense' => 79, 'selling_rate' => 0]);

        app(ChangeStatus::class)($booking, 'c', 'Sem carga', 7);

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_suspends_an_ocean_export_and_reactivates_it(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'm']);

        app(ChangeStatus::class)($booking, 'p', 'Aguardando draft', 7);
        $this->assertSame('p', $booking->fresh()->finalizado);
        $this->assertSame(['Embarque Suspenso', 'Aguardando draft', null, 7], $this->logged());

        app(ChangeStatus::class)($booking->fresh(), 'n', 'Draft chegou', 7);
        $this->assertSame('n', $booking->fresh()->finalizado);
        $this->assertSame(['Pendencia de Embarque Removida', 'Draft chegou', null, 7], $this->logged());
    }

    public function test_blocks_changes_the_legacy_screens_do_not_offer(): void
    {
        $import = $this->booking(['impor_expor' => 'i', 'tipo_embarque' => 'm']);
        $this->assertBlocked($import, 'p', 'Só é possível suspender uma exportação marítima.');
        $this->assertBlocked($import, 'n', 'Só é possível reativar uma reserva suspensa.');

        $cancelled = $this->booking(['finalizado' => 'c']);
        $this->assertBlocked($cancelled, 'c', 'A reserva já está cancelada.');
        $this->assertSame(0, DB::table('rastreamento')->count());
    }

    private function assertBlocked(Embarque $booking, string $status, string $message): void
    {
        try {
            app(ChangeStatus::class)($booking, $status, 'x', 7);
            $this->fail('Deveria bloquear.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array{0: string, 1: string, 2: ?string, 3: int} */
    private function logged(): array
    {
        $row = DB::table('rastreamento')->latest('id')->first();

        return [$row->assunto, $row->historico, $row->evento, (int) $row->created_by];
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG1', 'finalizado' => 'n', 'impor_expor' => 'i', 'tipo_embarque' => 'm']));
    }
}

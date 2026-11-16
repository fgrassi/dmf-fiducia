<?php

namespace Tests\Feature\Actions\ContainerTimes;

use App\Actions\ContainerTimes\SaveContainerTime;
use App\Enums\ContainerTime;
use App\Models\Embarque;
use App\Models\FinDemurrage;
use App\Models\FinDetention;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SaveContainerTimeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-16 10:00');
    }

    public function test_registers_a_demurrage_container_with_the_arrival_as_its_pickup_and_keeps_the_invoice(): void
    {
        Storage::fake('tracking_uploads');
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01']);

        $record = $this->save(ContainerTime::Demurrage, $booking, [
            'data_devolucao' => '2026-11-05',
            'file' => UploadedFile::fake()->create('Fatura do armador.pdf', 10, 'application/pdf'),
        ]);

        $this->assertSame(['ABCU1', '2026-11-01', '2026-11-05', 7, 10], [$record->container, $record->data_retirada->toDateString(), $record->data_devolucao->toDateString(), (int) $record->created_by, (int) $record->free_time]);
        $this->assertSame("demurrage_{$record->getKey()}_Fatura_do_armador.pdf", $record->ref_file);
        Storage::disk('tracking_uploads')->assertExists("demurrage/{$record->ref_file}");
    }

    public function test_blocks_a_duplicate_container_a_future_return_and_a_disallowed_file(): void
    {
        $booking = $this->booking();
        $this->save(ContainerTime::Demurrage, $booking);

        $this->assertBlocked(fn () => $this->save(ContainerTime::Demurrage, $booking), 'Não foi possível adicionar o Container. Container já está cadastrado.');
        $this->assertBlocked(fn () => $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU2', 'data_devolucao' => '2026-11-17']), 'A data máxima possível é o dia de hoje: 16/11/2026');
        $this->assertBlocked(fn () => $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU3', 'file' => UploadedFile::fake()->create('x.php', 1, 'text/x-php')]), 'Tipo de arquivo não permitido. Envie PDF, JPG ou PNG.');
    }

    public function test_changing_a_free_time_that_was_set_leaves_the_before_and_after_in_the_tracking(): void
    {
        $booking = $this->booking();
        $record = $this->save(ContainerTime::Demurrage, $booking);

        $this->save(ContainerTime::Demurrage, $booking, ['free_time' => 14], $record);

        $this->assertSame(14, (int) $record->fresh()->free_time);
        $this->assertSame(['Free Time Demurrage Modificado', 'O Free Time da reserva foi modificado de 10 para 14', 'x'], [DB::table('rastreamento')->value('assunto'), DB::table('rastreamento')->value('historico'), DB::table('rastreamento')->value('evento')]);
    }

    public function test_warns_the_demurrage_group_and_flags_the_booking_when_every_container_is_back_and_one_is_charged(): void
    {
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01']);
        $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU1', 'data_devolucao' => '2026-11-05', 'free_time' => 3]);

        $message = $this->lastMail();
        $this->assertSame('Embarque com incidencia de Demurrage', $message->getSubject());
        $this->assertSame('info_demurrage@dmflog.com.br', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('ABCU1', $message->getHtmlBody());
        $this->assertStringContainsString('2 dias', $message->getHtmlBody());
        $this->assertSame(1, (int) $booking->fresh()->ctr_demurrage);
    }

    public function test_within_free_time_clears_the_flag_without_mail_and_an_open_container_leaves_everything_alone(): void
    {
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01', 'ctr_demurrage' => 1]);
        $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU1', 'data_devolucao' => '2026-11-03', 'free_time' => 10]);

        $this->assertSame(0, (int) $booking->fresh()->ctr_demurrage);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());

        $booking->forceFill(['ctr_demurrage' => 1])->save();
        $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU2', 'data_devolucao' => null, 'free_time' => 1]);

        $this->assertSame(1, (int) $booking->fresh()->ctr_demurrage);
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_closes_an_ocean_import_once_returned_arrived_and_in_a_box_ignoring_lcl(): void
    {
        $booking = $this->booking(['chegada_efetiva' => '2026-11-01']);
        DB::table('arrival_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => 7]);

        $this->save(ContainerTime::Demurrage, $booking, ['container' => 'ABCU1', 'data_devolucao' => '2026-11-03', 'free_time' => 10]);
        $this->assertSame('n', $booking->fresh()->finalizado, 'ainda sem caixa');

        DB::table('controle_caixa_embarque')->insert(['embarque_id' => $booking->getKey(), 'controle_caixa_id' => 1]);
        $this->save(ContainerTime::Demurrage, $booking, ['container' => 'LCL1', 'ref_tipo_container' => 20, 'data_devolucao' => null]);

        $this->assertSame('e', $booking->fresh()->finalizado);
    }

    public function test_detention_keeps_its_own_dates_warns_its_group_and_never_closes_the_booking(): void
    {
        $booking = $this->booking(['impor_expor' => 'e', 'free_time_detention' => 2, 'chegada_efetiva' => '2026-11-01']);

        $record = $this->save(ContainerTime::Detention, $booking, ['data_retirada' => '2026-11-01', 'data_devolucao' => '2026-11-20']);

        $this->assertSame(['2026-11-01', '2026-11-20'], [$record->data_retirada->toDateString(), $record->data_devolucao->toDateString()]);
        $message = $this->lastMail();
        $this->assertSame('Embarque com incidencia de Detention', $message->getSubject());
        $this->assertSame('detention@dmflog.com.br', $message->getTo()[0]->getAddress());
        $this->assertSame('n', $booking->fresh()->finalizado);
    }

    public function test_day_math_counts_the_first_day_and_charges_only_past_the_free_time(): void
    {
        $record = fn (int $freeTime) => (new FinDemurrage)->forceFill(['data_retirada' => '2026-11-10', 'data_devolucao' => '2026-11-12', 'free_time' => $freeTime]);

        $this->assertSame(3, $record(2)->operationDays());
        $this->assertTrue($record(2)->isCharged());
        $this->assertSame(1, $record(2)->overdueDays());
        $this->assertFalse($record(3)->isCharged());
        $this->assertSame(0, $record(3)->overdueDays());
        $this->assertNull($record(5)->overdueDays());

        $open = (new FinDetention)->forceFill(['data_retirada' => '2026-11-10']);
        $this->assertNull($open->operationDays());
        $this->assertNull($open->isCharged());
    }

    /** @param array<string, mixed> $data */
    private function save(ContainerTime $type, Embarque $booking, array $data = [], $record = null)
    {
        return app(SaveContainerTime::class)($type, $booking, $data + ['container' => 'ABCU1', 'ref_tipo_container' => 1, 'free_time' => 10, 'data_devolucao' => null], $record, 7);
    }

    private function assertBlocked(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Deveria bloquear.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function lastMail()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    /** @param array<string, mixed> $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'BKG1', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'finalizado' => 'n']));
    }
}

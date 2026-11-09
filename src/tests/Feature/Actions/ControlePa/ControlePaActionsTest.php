<?php

namespace Tests\Feature\Actions\ControlePa;

use App\Actions\ControlePa\AttachControlePaFile;
use App\Actions\ControlePa\SetControlePaDate;
use App\Models\ControlePa;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ControlePaActionsTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tracking_uploads');
        $this->travelTo('2026-11-09 14:35');
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG10']);
    }

    public function test_dates_open_one_stage_after_the_other(): void
    {
        $row = $this->row(['flag_fi_dmf' => 0]);

        $this->assertDateRefused($row, ControlePa::DATE_SENT_TO_AGENT);

        $row->forceFill(['flag_fi_dmf' => 1])->save();
        app(SetControlePaDate::class)($row, ControlePa::DATE_SENT_TO_AGENT, '2026-11-02');
        $this->assertSame('2026-11-02', $row->fresh()->data_envio_fi_agente->toDateString());

        $this->assertDateRefused($row, ControlePa::DATE_CARRIER_PAYMENT);
        $this->assertDateRefused($row, ControlePa::DATE_CARRIER_RECEIPT);
    }

    public function test_invalid_date_is_refused(): void
    {
        $row = $this->row(['flag_fi_dmf' => 1]);

        $this->expectExceptionMessage('Data inválida.');

        app(SetControlePaDate::class)($row, ControlePa::DATE_SENT_TO_AGENT, 'ontem');
    }

    public function test_carrier_invoice_is_attached_to_the_booking_and_releases_payment(): void
    {
        $row = $this->row(['flag_fi_dmf' => 1, 'data_envio_fi_agente' => '2026-11-02']);

        app(AttachControlePaFile::class)($row, EmbEmbarqueFile::CARRIER_INVOICE, UploadedFile::fake()->create('FI armador (1).pdf', 10, 'application/pdf'), 7);

        $file = DB::table('cmn_files')->sole();
        $this->assertSame(['0911261435_FI_armador_1.pdf', 'Documento Anexo a Reserva', sha1((string) $file->id), 7], [$file->name, $file->subject, $file->uid, (int) $file->created_by]);
        Storage::disk('tracking_uploads')->assertExists('docs_emb/'.$file->name);
        $this->assertSame([[10, 37, (int) $file->id]], DB::table('emb_embarque_files')->get()->map(fn ($l) => [(int) $l->ref_embarque, (int) $l->ref_file_type, (int) $l->ref_file])->all());
        $this->assertSame(1, (int) $row->fresh()->flag_fi_armador);
        $this->assertTrue($row->fresh()->canSetDate(ControlePa::DATE_CARRIER_PAYMENT));
    }

    public function test_attach_is_refused_before_its_stage_and_for_unsafe_extensions(): void
    {
        $row = $this->row(['flag_fi_dmf' => 1]);

        try {
            app(AttachControlePaFile::class)($row, EmbEmbarqueFile::CARRIER_INVOICE, UploadedFile::fake()->create('fi.pdf', 10), 7);
            $this->fail('A etapa ainda não abriu.');
        } catch (DomainException $e) {
            $this->assertSame('Esta etapa ainda não foi liberada.', $e->getMessage());
        }

        $row->forceFill(['data_envio_fi_agente' => '2026-11-02'])->save();

        try {
            app(AttachControlePaFile::class)($row, EmbEmbarqueFile::CARRIER_INVOICE, UploadedFile::fake()->create('shell.php', 1), 7);
            $this->fail('A extensão deveria ser recusada.');
        } catch (DomainException $e) {
            $this->assertSame('Formato de arquivo não permitido.', $e->getMessage());
        }

        $this->assertSame(0, DB::table('cmn_files')->count() + DB::table('emb_embarque_files')->count());
    }

    public function test_open_and_completed_scopes_split_the_rows(): void
    {
        $open = $this->row(['flag_fi_dmf' => 1, 'data_envio_fi_agente' => '2026-11-02', 'flag_fi_armador' => 1, 'data_pgto_armador' => '2026-11-03']);
        $done = $this->row(['flag_fi_dmf' => 2, 'data_envio_fi_agente' => '2026-11-02', 'flag_fi_armador' => 1, 'data_pgto_armador' => '2026-11-03', 'data_recibo_armador' => '2026-11-04']);

        $this->assertSame([$open->getKey()], ControlePa::pending()->pluck('controle_pa_id')->all());
        $this->assertSame([$done->getKey()], ControlePa::completed()->pluck('controle_pa_id')->all());
    }

    private function assertDateRefused(ControlePa $row, string $field): void
    {
        try {
            app(SetControlePaDate::class)($row->fresh(), $field, '2026-11-05');
            $this->fail('A etapa ainda não abriu.');
        } catch (DomainException $e) {
            $this->assertSame('Esta etapa ainda não foi liberada.', $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function row(array $attributes): ControlePa
    {
        return ControlePa::forceCreate(['embarque_id' => 10, ...$attributes]);
    }
}

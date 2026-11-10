<?php

namespace Tests\Feature\Actions\Courier;

use App\Actions\Courier\CourierPdf;
use App\Actions\Courier\UploadCourierFile;
use App\Models\ControleCourier;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CourierPdfAndUploadTest extends TestCase
{
    use LegacySchema;

    public function test_pdf_shows_the_note_agent_bookings_and_the_total_and_half_in_dollars(): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'AGENTE UM']);
        DB::table('embarque')->insert([
            ['embarque_id' => 10, 'booking' => 'BKG10', 'house_bl' => 'HBL10', 'pessoa_id_representante' => 5],
            ['embarque_id' => 11, 'booking' => 'BKG11', 'house_bl' => 'HBL11', 'pessoa_id_representante' => null],
        ]);
        $courier = $this->courier(['numero' => 'AWB-9', 'nota' => 'N-77', 'valor' => 1234.5]);
        $courier->bookings()->create(['embarque_id' => 10]);
        $courier->bookings()->create(['embarque_id' => 11]);

        $pdf = app(CourierPdf::class)->render($courier);
        $pdf->setCompression(false);
        $text = $pdf->Output('', 'S');

        $this->assertStringStartsWith('%PDF', $text);

        foreach (['COURRIER NOTE', 'N-77', 'AGENTE UM', 'AWB-9', 'BKG10', 'HBL11', 'USD 1.234,50', 'USD 617,25', 'Date '.now()->format('d/m/Y'), 'BOFAUS3N'] as $expected) {
            $this->assertStringContainsString($expected, $text, $expected);
        }
    }

    public function test_pdf_of_a_courier_without_bookings_still_renders_with_no_agent(): void
    {
        $pdf = app(CourierPdf::class)->render($this->courier(['valor' => null]));

        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
    }

    public function test_upload_stores_the_file_under_courrier_with_a_clean_name_and_records_it(): void
    {
        Storage::fake('tracking_uploads');
        $courier = $this->courier();

        $name = app(UploadCourierFile::class)($courier, $this->pdf('meu arquivo (1) ç.pdf'));

        $this->assertMatchesRegularExpression('/^\d{6}\d{3,4}_meu_arquivo_1_\.pdf$/', $name);
        Storage::disk('tracking_uploads')->assertExists("courrier/{$name}");
        $this->assertSame($name, $courier->fresh()->arquivo);
    }

    public function test_a_second_upload_replaces_the_recorded_name_but_keeps_the_old_file(): void
    {
        Storage::fake('tracking_uploads');
        $courier = $this->courier();
        $this->travelTo(now()->setTime(10, 15));
        $first = app(UploadCourierFile::class)($courier, $this->pdf('a.pdf'));
        $this->travelTo(now()->setTime(11, 20));
        $second = app(UploadCourierFile::class)($courier, $this->pdf('b.pdf'));

        $this->assertSame($second, $courier->fresh()->arquivo);
        Storage::disk('tracking_uploads')->assertExists(["courrier/{$first}", "courrier/{$second}"]);
    }

    /** O fake do Laravel só olha o nome; por isso este teste e o seguinte usam arquivo real. */
    public function test_the_extension_comes_from_the_content_not_from_the_sent_name(): void
    {
        Storage::fake('tracking_uploads');
        $courier = $this->courier();

        $name = app(UploadCourierFile::class)($courier, $this->realFile('shell.php', self::PDF));

        $this->assertStringEndsWith('_shell.pdf', $name);
        $this->assertSame([$name], array_map('basename', Storage::disk('tracking_uploads')->files('courrier')));
    }

    public function test_refuses_content_that_is_not_pdf_or_image_even_with_a_pdf_name(): void
    {
        Storage::fake('tracking_uploads');
        $courier = $this->courier();

        $this->expectException(DomainException::class);

        try {
            app(UploadCourierFile::class)($courier, $this->realFile('awb.pdf', '<?php echo 1;'));
        } finally {
            $this->assertNull($courier->fresh()->arquivo);
            $this->assertSame([], Storage::disk('tracking_uploads')->files('courrier'));
        }
    }

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    /** Arquivo de verdade no disco: o fake do Laravel define o MIME pelo nome, e aqui o que vale é o conteúdo. */
    private function realFile(string $name, string $content): UploadedFile
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'courier'), $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function courier(array $attributes = []): ControleCourier
    {
        return ControleCourier::create($attributes + ['numero' => 'N1', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);
    }
}

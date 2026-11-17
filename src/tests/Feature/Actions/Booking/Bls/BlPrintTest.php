<?php

namespace Tests\Feature\Actions\Booking\Bls;

use App\Actions\Booking\Bls\BlPdf;
use App\Actions\Booking\Bls\PrintBl;
use App\Actions\Booking\Bls\SaveBl;
use App\Models\Bl;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BlPrintTest extends TestCase
{
    use LegacySchema;

    public function test_the_pdf_carries_the_bl_data_and_the_full_layout(): void
    {
        $text = $this->text($this->bl(['shipper' => 'ACME LTDA', 'mbl_number' => 'MBL-77', 'freight_at' => 'ORIGIN']));

        foreach (['BILL OF LADING', 'OCEAN', 'ACME LTDA', 'MBL-77', 'NON NEGOTIABLE', 'FREIGHT PAYABLE AT', 'DMF LOGISTICS DO BRASIL'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringNotContainsString('(ORIGINAL)', $text);
    }

    public function test_preprinted_and_draft_variants_drop_the_letterhead_and_the_stamp(): void
    {
        $bl = $this->bl();

        $preprinted = $this->text($bl, preprinted: true);
        $this->assertStringNotContainsString('DMF LOGISTICS DO BRASIL', $preprinted);
        $this->assertStringNotContainsString('OCEAN', $preprinted);
        $this->assertStringContainsString('/JS', $preprinted);
        $this->assertStringNotContainsString('/JS', $this->text($bl));

        $draft = $this->text($bl, draft: true);
        $this->assertStringContainsString('DRAFT', $draft);
        $this->assertStringNotContainsString('NON NEGOTIABLE', $draft);
    }

    public function test_printing_the_original_counts_and_stamps_original_only_the_first_three_times(): void
    {
        $bl = $this->bl();
        $stamps = [];

        foreach (range(1, 4) as $_) {
            app(PrintBl::class)($bl, PrintBl::ORIGINAL, true);
            $bl->refresh();
            $stamps[] = (int) $bl->cntOriginal;
        }

        $this->assertSame([1, 2, 3, 4], $stamps);
        $this->assertSame(4, (int) $bl->cntImpressao);
        $this->assertStringContainsString('(ORIGINAL)', $this->text($bl, original: true, previous: 2, preprinted: true));
        $this->assertStringContainsString('(NON NEGOTIABLE)', $this->text($bl, original: true, previous: 3, preprinted: true));
    }

    public function test_printing_non_negotiable_or_previewing_does_not_count(): void
    {
        $bl = $this->bl();

        app(PrintBl::class)($bl, PrintBl::NON_NEGOTIABLE, true);
        app(PrintBl::class)($bl);

        $bl->refresh();
        $this->assertSame([0, 0], [(int) $bl->cntOriginal, (int) $bl->cntImpressao]);
    }

    public function test_a_long_description_and_many_containers_go_to_the_attachment_page(): void
    {
        $bl = $this->bl(['desc_pkgs' => collect(range(1, 120))->map(fn (int $n) => "ITEM {$n} OF THE CARGO DESCRIPTION")->implode("\n")]);
        DB::table('container')->insert(collect(range(1, 40))->map(fn (int $n) => ['bl_id' => $bl->getKey(), 'numero' => "CONT{$n}"])->all());

        $pdf = app(BlPdf::class)->render($bl);

        $this->assertGreaterThan(1, $pdf->getNumPages());
        $pdf->setCompression(false);
        $this->assertStringContainsString('ATTACHMENT NO.: 1 0F', $pdf->Output('', 'S'));
    }

    public function test_finalizing_approves_the_bl_stores_the_pdf_and_mails_the_documents_contacts(): void
    {
        Storage::fake('tracking_uploads');
        $booking = $this->booking(['despachante_id' => 3, 'invoice' => 'INV-9', 'pessoa_id_cliente' => 5]);
        DB::table('pessoa')->insert(['pessoa_id' => 5, 'nome' => 'ACME']);
        DB::table('usuario')->insert([
            ['usuario_id' => 1, 'nome' => 'Docs', 'email' => 'docs@client.com', 'receber_documentos' => 's'],
            ['usuario_id' => 2, 'nome' => 'Silent', 'email' => 'silent@client.com', 'receber_documentos' => 'n'],
            ['usuario_id' => 3, 'nome' => 'Shipper contact', 'email' => 'shipper@client.com', 'receber_documentos' => 'n'],
        ]);
        DB::table('embarque_contato_cliente')->insert([['embarque_id' => $booking->getKey(), 'usuario_id' => 1], ['embarque_id' => $booking->getKey(), 'usuario_id' => 2]]);
        DB::table('embarque_contato')->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => 3]);
        DB::table('despachante_contato')->insert([
            ['ref_despachante' => 3, 'name' => 'Broker', 'email' => 'broker@x.com', 'tipo' => 'e', 'status' => 1],
            ['ref_despachante' => 3, 'name' => 'Finance', 'email' => 'fin@x.com', 'tipo' => 'f', 'status' => 1],
        ]);

        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1'], 7, finalize: true);

        $this->assertSame(Bl::APPROVED, $bl->fresh()->status);
        $file = DB::table('emb_embarque_files')->where('ref_embarque', $booking->getKey())->first();
        $this->assertSame(1, (int) $file->ref_file_type);
        $name = DB::table('cmn_files')->where('id', $file->ref_file)->value('name');
        $this->assertStringStartsWith('dmf_hblHBL-1_', $name);
        Storage::disk('tracking_uploads')->assertExists("docs_emb/{$name}");

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame("DMF Logistics: CÓPIA DE BL - Shipper: ACME / INV-9 / {$booking->booking}", $message->getSubject());
        $this->assertEqualsCanonicalizing(['docs@client.com', 'shipper@client.com', 'broker@x.com', 'documentacao@dmflog.com.br'], collect($message->getTo())->map->getAddress()->all());
        $this->assertCount(1, $message->getAttachments());
    }

    public function test_changing_an_approved_bl_stores_a_new_pdf_but_an_open_one_does_not(): void
    {
        Storage::fake('tracking_uploads');
        $booking = $this->booking();
        $bl = app(SaveBl::class)($booking, null, ['bl' => 'HBL-1'], 7);

        app(SaveBl::class)($booking, $bl, ['shipper' => 'A'], 7);
        $this->assertSame(0, DB::table('emb_embarque_files')->count());

        $bl->forceFill(['status' => Bl::APPROVED])->save();
        app(SaveBl::class)($booking, $bl, ['shipper' => 'B'], 7);
        $this->assertSame(1, DB::table('emb_embarque_files')->count());
    }

    public function test_finalizing_an_lcl_import_with_a_different_factor_alerts_the_import_team(): void
    {
        Storage::fake('tracking_uploads');
        $booking = $this->booking(['impor_expor' => 'i', 'peso_cotacao' => 5, 'volume_cotacao' => 2]);
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => 20]);

        app(SaveBl::class)($booking, null, ['bl' => 'HBL-1', 'gross_weight' => '2.000,00', 'volume' => '1,5'], 7, finalize: true);

        $this->assertContains('Verificar despesas - '.$booking->booking, $this->sentSubjects()->all());
    }

    private function text(Bl $bl, bool $preprinted = false, bool $draft = false, bool $original = false, int $previous = 0): string
    {
        $pdf = app(BlPdf::class)->render($bl, $preprinted, $draft, $original, $previous);
        $pdf->setCompression(false);

        return $pdf->Output('', 'S');
    }

    private function bl(array $attributes = []): Bl
    {
        return Bl::create($attributes + ['embarque_id' => $this->booking()->getKey(), 'bl' => 'HBL-1', 'status' => Bl::OPEN]);
    }

    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId($attributes + ['booking' => 'EXP'.random_int(1000, 9999), 'impor_expor' => 'e', 'tipo_embarque' => 'm']));
    }
}

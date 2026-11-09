<?php

namespace Tests\Feature\Actions\Booking\Notes;

use App\Actions\Booking\Notes\ConvertNoteToEuro;
use App\Actions\Booking\Notes\CreateNote;
use App\Actions\Booking\Notes\GenerateNotePdf;
use App\Actions\Booking\Notes\SendNote;
use App\Enums\NoteType;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class NoteDocumentsTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-05 10:00');
        Storage::fake('tracking_uploads');
        DB::table('cmn_currencies')->insert([['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$'], ['id' => 3, 'name' => 'Euro', 'symbol' => '€']]);
        DB::table('pessoa')->insert(['pessoa_id' => 55, 'nome' => 'Agente SA', 'endereco' => 'Rua A, 1', 'cnpj' => '123']);
    }

    #[DataProvider('pdfScenarios')]
    public function test_generates_the_pdf_stores_it_and_points_the_note_to_the_file(NoteType $type, bool $withHandling, bool $hideValues): void
    {
        $booking = $this->booking(['booking' => 'EXP 1/é']);
        $expenses = [$this->expense($booking, 10, 100, 160), $this->expense($booking, 11, 50, 70), ...($withHandling ? [$this->expense($booking, 1612, 0, 40)] : [])];
        $noteId = app(CreateNote::class)($booking, $type, $expenses, 7);

        $path = app(GenerateNotePdf::class)($type, $noteId, 7, $hideValues);

        $name = $type === NoteType::Debit ? 'DN0001-2026 - EXP_1e' : 'CN0001-2026 - EXP 1_é';
        $this->assertSame(($type === NoteType::Debit ? 'debitNotes' : 'creditNotes')."/{$name}.pdf", $path);
        $this->assertStringStartsWith('%PDF', Storage::disk('tracking_uploads')->get($path));
        $file = DB::table('cmn_files')->first();
        $this->assertSame([$name, $type->label().' EXP 1/é', sha1((string) $file->id)], [$file->name, $file->subject, $file->uid]);
        $this->assertSame($file->id, DB::table($type->table())->find($noteId)->ref_file);
    }

    /** @return array<string, array{NoteType, bool, bool}> */
    public static function pdfScenarios(): array
    {
        return [
            'débito' => [NoteType::Debit, false, false],
            'débito com handling' => [NoteType::Debit, true, false],
            'crédito' => [NoteType::Credit, false, false],
            'crédito com handling' => [NoteType::Credit, true, false],
            'crédito sem valores' => [NoteType::Credit, false, true],
        ];
    }

    public function test_converting_to_euro_multiplies_the_amounts_and_regenerates_the_pdf_once(): void
    {
        $booking = $this->booking();
        $noteId = app(CreateNote::class)($booking, NoteType::Debit, [$this->expense($booking, 10, 100, 160)], 7);
        DB::table('fin_debit_notes')->where('id', $noteId)->update(['handling' => 10, 'origin_comission' => 4]);

        app(ConvertNoteToEuro::class)(NoteType::Debit, $noteId, 0.5, 6.1, 7);

        $note = DB::table('fin_debit_notes')->find($noteId);
        $this->assertEquals([3, 0.5, 6.1], [$note->moeda_id, $note->fator_conversao, $note->dolar_rate]);
        $this->assertEquals([30.0, 5.0, 2.0, 15.0], [$note->gross_profit, $note->handling, $note->origin_comission, $note->due_to_dmf]);
        $this->assertNotNull($note->ref_file);

        $this->expectException(DomainException::class);
        app(ConvertNoteToEuro::class)(NoteType::Debit, $noteId, 0.5, 6.1, 7);
    }

    public function test_a_conversion_without_a_positive_factor_or_rate_changes_nothing(): void
    {
        $booking = $this->booking();
        $noteId = app(CreateNote::class)($booking, NoteType::Credit, [$this->expense($booking, 10, 100, 160)], 7);

        try {
            app(ConvertNoteToEuro::class)(NoteType::Credit, $noteId, 0, 6.1, 7);
            $this->fail('A conversão com fator zero deveria ser recusada.');
        } catch (DomainException) {
            $this->assertSame(2, (int) DB::table('fin_credit_notes')->find($noteId)->moeda_id);
        }
    }

    public function test_sending_a_debit_note_mails_finance_and_the_authorized_export_contacts_with_the_pdf(): void
    {
        $booking = $this->booking(['house_bl' => 'HBL1', 'master_bl' => 'MBL1']);
        $noteId = app(CreateNote::class)($booking, NoteType::Debit, [$this->expense($booking, 10, 100, 160)], 7);
        DB::table('agente_contato')->insert([
            ['ref_pessoa' => 55, 'name' => 'Exp', 'email' => 'exp@agente.com', 'operacao' => 'e', 'autorizado' => '1', 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 55, 'name' => 'Ambos', 'email' => 'ambos@agente.com', 'operacao' => 'a', 'autorizado' => '1', 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 55, 'name' => 'Imp', 'email' => 'imp@agente.com', 'operacao' => 'i', 'autorizado' => '1', 'created_at' => '2026-01-01'],
            ['ref_pessoa' => 55, 'name' => 'Bloq', 'email' => 'bloq@agente.com', 'operacao' => 'e', 'autorizado' => '0', 'created_at' => '2026-01-01'],
        ]);
        app(GenerateNotePdf::class)(NoteType::Debit, $noteId, 7);

        app(SendNote::class)(NoteType::Debit, $noteId, 7);

        $mail = $this->lastMail();
        $this->assertSame('DMF Logistics: DEBIT NOTE DN 0001/2026 - VESSEL  - HBL HBL1 - BOOKING EXP1', $mail->getSubject());
        $this->assertEqualsCanonicalizing(['financeiro@dmflog.com.br', 'exp@agente.com', 'ambos@agente.com'], $this->recipients($mail));
        $this->assertSame(['DN0001-2026 - EXP1.pdf'], array_map(fn ($part) => $part->getFilename(), $mail->getAttachments()));
        $this->assertStringContainsString('MBL: </td><td>MBL1', $mail->getHtmlBody());
        $this->assertSame(1, (int) DB::table('fin_debit_notes')->find($noteId)->status);
        $this->assertSame(['Enviado: Debit Note', 'e'], [DB::table('rastreamento')->orderByDesc('id')->value('assunto'), DB::table('rastreamento')->orderByDesc('id')->value('evento')]);
    }

    public function test_sending_a_credit_note_adds_the_finance_manager_skips_contacts_on_imports_and_does_not_log(): void
    {
        $booking = $this->booking(['impor_expor' => 'i']);
        $noteId = app(CreateNote::class)($booking, NoteType::Credit, [$this->expense($booking, 10, 100, 160)], 7);
        DB::table('agente_contato')->insert(['ref_pessoa' => 55, 'name' => 'Exp', 'email' => 'exp@agente.com', 'operacao' => 'a', 'autorizado' => '1', 'created_at' => '2026-01-01']);
        app(GenerateNotePdf::class)(NoteType::Credit, $noteId, 7);
        $trackingBefore = DB::table('rastreamento')->count();

        app(SendNote::class)(NoteType::Credit, $noteId, 7);

        $this->assertEqualsCanonicalizing(['marcia@dmflog.com.br', 'financeiro@dmflog.com.br'], $this->recipients($this->lastMail()));
        $this->assertSame(1, (int) DB::table('fin_credit_notes')->find($noteId)->status);
        $this->assertSame($trackingBefore, DB::table('rastreamento')->count());
    }

    public function test_euro_agents_with_a_dollar_note_only_ask_finance_to_convert_it(): void
    {
        $booking = $this->booking(['pessoa_id_representante' => 3586]);
        $noteId = app(CreateNote::class)($booking, NoteType::Debit, [$this->expense($booking, 10, 100, 160)], 7);

        app(SendNote::class)(NoteType::Debit, $noteId, 7);

        $mail = $this->lastMail();
        $this->assertStringStartsWith('DMF Logistics: CONVERSÃO DE DEBIT NOTE', $mail->getSubject());
        $this->assertEqualsCanonicalizing(['marcia@dmflog.com.br', 'financeiro@dmflog.com.br'], $this->recipients($mail));
        $this->assertSame([], $mail->getAttachments());
        $this->assertSame(1, (int) DB::table('fin_debit_notes')->find($noteId)->status);
    }

    public function test_sending_without_a_generated_pdf_is_refused_and_sends_nothing(): void
    {
        $booking = $this->booking();
        $noteId = app(CreateNote::class)($booking, NoteType::Debit, [$this->expense($booking, 10, 100, 160)], 7);

        try {
            app(SendNote::class)(NoteType::Debit, $noteId, 7);
            $this->fail('O envio sem PDF deveria ser recusado.');
        } catch (DomainException $e) {
            $this->assertSame('Gere o PDF da nota antes de enviar.', $e->getMessage());
        }

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
        $this->assertSame(0, (int) DB::table('fin_debit_notes')->find($noteId)->status);
    }

    private function lastMail(): Email
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    /** @return list<string> */
    private function recipients(Email $mail): array
    {
        return array_map(fn ($address) => $address->getAddress(), $mail->getTo());
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes = []): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'EXP1', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'pessoa_id_representante' => 55], ...$attributes]));
    }

    private function expense(Embarque $booking, int $expenseId, float $buying, float $selling): int
    {
        return DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => $expenseId, 'buying_rate' => $buying, 'selling_rate' => $selling, 'flag_exw' => 0, 'ref_currency_br' => 2, 'ref_currency_sr' => 2, 'billing' => 'a']);
    }
}

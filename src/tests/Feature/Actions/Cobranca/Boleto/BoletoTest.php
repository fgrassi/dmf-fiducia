<?php

namespace Tests\Feature\Actions\Cobranca\Boleto;

use App\Actions\Cobranca\Boleto\CancelBoleto;
use App\Actions\Cobranca\Boleto\CheckBoletoStatus;
use App\Actions\Cobranca\Boleto\IssueBoleto;
use App\Actions\Cobranca\Documents\InstructionPdf;
use App\Models\FinChargesBoletoSicredi;
use DomainException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\BuildsBoletos;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BoletoTest extends TestCase
{
    use BuildsBoletos, BuildsCharges, LegacySchema;

    public function test_issues_the_boleto_with_the_nfse_taker_and_stores_the_pdf(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge();
        $due = today()->addDays(7)->toDateString();

        ['boleto' => $boleto, 'reused' => $reused] = app(IssueBoleto::class)($charge, 1, $due);

        $this->assertFalse($reused);
        $this->assertSame(['emitido', '211001234', '748911210012345', 995.0], [$boleto->status, $boleto->nosso_numero, $boleto->linha_digitavel, $boleto->valor]);
        Storage::disk('tracking_uploads')->assertExists($boleto->pdfPath());
        $request = Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/boletos'))->sole()[0];
        $this->assertEquals([995, $due, sprintf('TRK%07d', $charge->id)], [$request['valor'], $request['dataVencimento'], $request['seuNumero']]);
        $this->assertSame([
            'tipoPessoa' => 'PESSOA_JURIDICA', 'documento' => '07052088000134', 'nome' => 'CLIENTE IMPORTADORA DE UM NOME BEM COMPR',
            'endereco' => 'Rua Borges de Medeiros 274', 'cidade' => 'Santa Cruz do Sul', 'uf' => 'RS', 'cep' => '96810034', 'email' => 'fin@cliente.test',
        ], $request['pagador']);
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer jwt') && $request->hasHeader('cooperativa', '6789'));
    }

    public function test_an_issued_boleto_is_reused(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge();
        app(IssueBoleto::class)($charge, 1);

        $this->assertTrue(app(IssueBoleto::class)($charge, 1)['reused']);
        $this->assertSame(1, FinChargesBoletoSicredi::count());
    }

    public function test_a_failed_pdf_is_downloaded_again_without_registering_another_boleto(): void
    {
        $this->sicredi(pdf: 'indisponível');
        $charge = $this->boletoCharge();

        foreach ([1, 2] as $attempt) {
            try {
                app(IssueBoleto::class)($charge, 1);
                $this->fail('deveria falhar sem o PDF');
            } catch (DomainException) {
            }
        }

        $this->assertSame(['erro'], FinChargesBoletoSicredi::pluck('status')->all());
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/boletos')));
    }

    public function test_a_pdf_not_released_yet_is_retried(): void
    {
        $this->sicredi(Http::sequence()->push(['message' => 'Boleto não localizado'], 422)->push('%PDF-1.4 boleto'));

        $boleto = app(IssueBoleto::class)($this->boletoCharge(), 1)['boleto'];

        $this->assertSame('emitido', $boleto->status);
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/boletos/pdf') && $r->hasHeader('cooperativa', '6789') && $r->hasHeader('posto', '03'));
    }

    public function test_an_issued_boleto_without_the_pdf_downloads_it_again(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge();
        app(IssueBoleto::class)($charge, 1)['boleto']->update(['ref_file' => null]);

        ['boleto' => $boleto, 'reused' => $reused] = app(IssueBoleto::class)($charge, 1);

        $this->assertFalse($reused);
        $this->assertNotNull($boleto->ref_file);
        $this->assertSame(1, FinChargesBoletoSicredi::count());
    }

    #[TestWith(['%PDF-1.4 boleto', 'emitido', 'EM CARTEIRA. PDF do boleto baixado.'])]
    #[TestWith(['indisponível', 'erro', 'EM CARTEIRA. Boleto cadastrado na Sicredi'])]
    public function test_the_status_check_only_marks_as_issued_with_the_pdf(string $pdf, string $status, string $message): void
    {
        $this->sicredi($pdf, situation: 'EM CARTEIRA');
        $charge = $this->boletoCharge();

        try {
            app(IssueBoleto::class)($charge, 1);
        } catch (DomainException) {
        }

        $boleto = FinChargesBoletoSicredi::sole();
        $boleto->update(['ref_file' => null]);

        $this->assertStringStartsWith($message, app(CheckBoletoStatus::class)($boleto, 1));
        $this->assertSame($status, $boleto->fresh()->status);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_the_due_date_must_be_after_today(int $days): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge();

        $this->expectExceptionMessage('posterior ao dia atual');

        try {
            app(IssueBoleto::class)($charge, 1, today()->addDays($days)->toDateString());
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_nothing_is_sent_when_sicredi_is_not_configured(): void
    {
        $this->sicredi();
        config(['services.sicredi.url' => null]);
        $charge = $this->boletoCharge();

        $this->expectExceptionMessage('não configurada');

        try {
            app(IssueBoleto::class)($charge, 1);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_automatic_boleto_goes_attached_to_the_instruction_email(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge(automatic: true);

        app(InstructionPdf::class)($charge);

        $this->assertSame('emitido', FinChargesBoletoSicredi::sole()->status);
        $this->assertDatabaseHas('fin_charges_log', ['ref_charge' => $charge->id, 'historico' => 'Boleto Sicredi emitido automaticamente com a IC.']);
        $names = collect(app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getAttachments())->map->getFilename();
        $this->assertTrue($names->contains(fn (string $n) => str_starts_with($n, 'boleto_sicredi_')), $names->implode(', '));
    }

    public function test_a_failed_automatic_boleto_is_logged_and_does_not_stop_the_instruction(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge(automatic: true);
        DB::table('pessoa')->where('pessoa_id', 20)->update(['cep' => '']);

        app(InstructionPdf::class)($charge);

        $this->assertStringStartsWith('Falha na emissão automática do boleto Sicredi: Endereço do pagador', DB::table('fin_charges_log')->value('historico'));
        $this->assertCount(1, $this->sentSubjects());
    }

    public function test_cancel_and_status_check_do_not_change_the_charge(): void
    {
        $this->sicredi();
        $charge = $this->boletoCharge();
        $boleto = app(IssueBoleto::class)($charge, 1)['boleto'];

        $this->assertSame('LIQUIDADO PIX', app(CheckBoletoStatus::class)($boleto, 1));
        $this->assertSame('liquidado', $boleto->status);

        $boleto->update(['status' => 'emitido']);
        app(CancelBoleto::class)($boleto, 1);

        $this->assertSame('baixado', $boleto->status);
        $this->assertSame(0, $charge->fresh()->status->value);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/211001234/baixa') && $r->hasHeader('codigoBeneficiario', '12345'));
    }
}

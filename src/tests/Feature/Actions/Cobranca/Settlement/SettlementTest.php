<?php

namespace Tests\Feature\Actions\Cobranca\Settlement;

use App\Actions\Cobranca\Documents\ChargeRecipients;
use App\Actions\Cobranca\Documents\DeclarationPdf;
use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Receipts\GenerateReceipts;
use App\Actions\Cobranca\Settlement\BankSettlement;
use App\Actions\Cobranca\Settlement\BoletoSettlement;
use App\Actions\Cobranca\Settlement\ManualSettlement;
use App\Enums\Cobranca\ChargeStatus;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use TCPDF;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SettlementTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    private const PAYMENT = [
        'ref_bank' => 1, 'received_value' => '1.234,56', 'interest' => '10,50', 'fine' => '', 'discount' => '5,00', 'tax_boleto' => null,
        'discount_obs' => 'cortesia', 'payment_date' => '2026-10-26', 'payment_confirmation' => '2026-10-27', 'escritorio_id' => '7',
        'autoriza_liberacao_embarque' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-27 09:30');
        DB::table('cmn_banks')->insert(['id' => 1, 'name' => 'Itaú', 'bank_number' => '341', 'created_by' => 1]);
        $this->nfse = $this->mock(IssueNfse::class);
        $this->nfse->shouldReceive('forCharge')->andReturnNull()->byDefault();
    }

    private $nfse;

    private function mockReceipts(?string $warning = null): void
    {
        $this->mock(GenerateReceipts::class)->shouldReceive('__invoke')->once()->andReturn(['groups' => [], 'warning' => $warning]);
    }

    public function test_bank_settlement_pays_the_charge_and_saves_the_parsed_payment(): void
    {
        $this->mockReceipts('aviso dos recibos');
        $charge = $this->charge();
        $user = $this->user();

        $result = app(BankSettlement::class)($charge, self::PAYMENT, $user, ['a@x.com', 'A@x.com']);

        $charge->refresh();
        $this->assertSame(ChargeStatus::Paid, $charge->status);
        $this->assertSame([1234.56, 10.5, null, 5.0], [(float) $charge->received_value, (float) $charge->interest, $charge->fine, (float) $charge->discount]);
        $this->assertSame(['2026-10-26', '2026-10-27'], [substr((string) $charge->payment_date, 0, 10), substr((string) $charge->payment_confirmation, 0, 10)]);
        $this->assertSame([7, 1, 'on', 0], [(int) $charge->escritorio_id, (int) $charge->discount_status, $charge->autoriza_liberacao_embarque, (int) $charge->status_envio]);
        $this->assertSame('["a@x.com"]', $charge->emails_envio_json);
        $this->assertSame(['aviso dos recibos', null], [$result['warning'], $result['nfse']]);

        $log = DB::table('fin_charges_log')->first();
        $this->assertSame(['baixa_bancaria', 0, 1], [$log->acao, $log->status_anterior, $log->status_novo]);
        $this->assertStringContainsString('Banco: Itaú (341)', $log->historico);
        $this->assertSame('Payment received', DB::table('transborda')->value('descr'));
    }

    public function test_bank_settlement_does_not_re_release_a_shipment_already_sent(): void
    {
        $this->mockReceipts();
        $charge = $this->charge(['status_envio' => 1]);

        app(BankSettlement::class)($charge, self::PAYMENT, $this->user());

        $this->assertNull($charge->refresh()->data_envio);
    }

    public function test_bank_settlement_validates_before_touching_the_charge(): void
    {
        $charge = $this->charge();
        $settle = fn (array $data) => app(BankSettlement::class)($charge, $data, $this->user());

        $this->assertRefused(fn () => $settle([...self::PAYMENT, 'ref_bank' => '']), 'Informe o banco.');
        $this->assertRefused(fn () => $settle([...self::PAYMENT, 'received_value' => 'abc']), 'Valor Recebido: "abc" não é um número.');
        $this->assertRefused(fn () => app(BankSettlement::class)($this->charge(['status' => 1], ['embarque_id' => 11]), self::PAYMENT, $this->user()), 'Somente cobranças em aberto podem receber baixa bancária.');
        $this->assertSame(ChargeStatus::Open, $charge->refresh()->status);
    }

    public function test_manual_settlement_requires_a_justification_and_notifies_finance(): void
    {
        $charge = $this->charge(['escritorio_id' => 3]);

        $this->assertRefused(fn () => app(ManualSettlement::class)($charge, ' ab ', null, false, $this->user()), 'O comprimento de Justificativa precisa ser entre 3 e 1000.');

        app(ManualSettlement::class)($charge, 'Pago via PIX', null, true, $this->user('financial'), ['x@x.com']);

        $charge->refresh();
        $this->assertSame(ChargeStatus::ManualSettlement, $charge->status);
        $this->assertStringStartsWith("Pago via PIX\rResponsável: Ana\rData: 27/10/2026", $charge->obs);
        $this->assertSame([3, 'on', 0, '2026-10-27'], [(int) $charge->escritorio_id, $charge->autoriza_liberacao_embarque, (int) $charge->status_envio, substr((string) $charge->due_date, 0, 10)]);
        $this->assertSame('baixa_manual', DB::table('fin_charges_log')->value('acao'));
        $this->assertSame(['DMF Logistics: Cobrança com Baixa Manual'], $this->sentSubjects()->all());
    }

    public function test_manual_settlement_of_a_settled_charge_is_refused(): void
    {
        $this->assertRefused(fn () => app(ManualSettlement::class)($this->charge(['status' => 3]), 'justificativa', null, false, $this->user()), 'Somente cobranças em aberto podem receber baixa manual.');
    }

    public function test_boleto_settlement_creates_the_declaration_stores_the_pdf_and_emails_the_client(): void
    {
        Storage::fake('tracking_uploads');
        $pdf = Mockery::mock(TCPDF::class);
        $pdf->shouldReceive('Output')->andReturn('%PDF-fake');
        $this->mock(DeclarationPdf::class)->shouldReceive('render')->once()->andReturn($pdf);
        $this->mock(ChargeRecipients::class)->shouldReceive('__invoke')->andReturn(['cliente@x.com' => 'Cliente']);
        $charge = $this->charge();

        app(BoletoSettlement::class)($charge, self::PAYMENT, $this->user());

        $this->assertSame(ChargeStatus::Boleto, $charge->refresh()->status);
        $declaration = DB::table('fin_payment_boleto')->first();
        $this->assertSame([$charge->id, '2026-10-27'], [(int) $declaration->ref_charge, substr($declaration->due_to, 0, 10)]);
        $name = DB::table('cmn_files')->where('id', $declaration->ref_file)->value('name');
        Storage::disk('tracking_uploads')->assertExists("declaracao/{$name}.pdf");
        $this->assertSame('baixa_boleto', DB::table('fin_charges_log')->value('acao'));
        $this->assertStringContainsString('Demonstrativo de Baixa do Boleto Bancário', $this->sentSubjects()->first());
    }

    private function assertRefused(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Esperava DomainException.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}

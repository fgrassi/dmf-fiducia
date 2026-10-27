<?php

namespace Tests\Feature\Actions\Cobranca\Generation;

use App\Actions\Cobranca\Generation\GenerateCharges;
use App\Actions\InvoiceControls\ChargeInvoiceControls;
use App\Enums\Cobranca\InvoiceControlStatus;
use App\Models\ControleInvoiceMaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class GenerateChargesTest extends TestCase
{
    use LegacySchema;

    private const USER = 1;

    private const BOOKING = 10;

    private const BL = 20;

    private const CARRIER = 19;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('tracking_uploads');
    }

    public function test_creates_charge_with_items_and_closes_pending_expenses(): void
    {
        $this->maritimeExportBooking(carrierRequiresInvoice: false);

        $created = $this->generate();

        $this->assertSame(1, $created);
        $charge = DB::table('fin_charges')->where('ref_booking', self::BOOKING)->sole();
        $this->assertSame(self::BL, (int) $charge->ref_bl);
        $this->assertSame(GenerateCharges::TYPE_NORMAL, (int) $charge->ref_type);
        $this->assertEquals(100.0, $charge->total_value);
        $this->assertEquals(0.0, $charge->valor_iof);
        $this->assertEquals(100.0, DB::table('fin_charges_items')->where('ref_charge', $charge->id)->sum('item_value'));
        $this->assertSame(0, (int) DB::table('embarque')->where('embarque_id', self::BOOKING)->value('ctr_desp'));
        $this->assertContains('DMF Logistics: 1 cobrança(s) gerada(s) com sucesso', $this->sentSubjects());
        $this->assertCount(1, Storage::disk('tracking_uploads')->files('cobranca'));
    }

    public function test_does_not_charge_booking_already_fully_billed(): void
    {
        $this->maritimeExportBooking(carrierRequiresInvoice: false);
        $this->generate();

        $this->assertSame(0, $this->generate());
        $this->assertSame(1, DB::table('fin_charges')->count());
    }

    public function test_blocks_charge_and_creates_pending_invoice_control_when_carrier_requires_invoice(): void
    {
        $this->maritimeExportBooking(carrierRequiresInvoice: true);

        $this->assertSame(0, $this->generate());

        $this->assertSame(0, DB::table('fin_charges')->count());
        $control = ControleInvoiceMaster::sole();
        $this->assertSame(InvoiceControlStatus::Pending, $control->status);
        $this->assertSame('MBL1', $control->mbl_number);
        $this->assertSame(['criacao'], $control->logs()->pluck('acao')->all());
        $this->assertContains('DMF Logistics: Controle de Invoice: pendentes e aguardando arquivo', $this->sentSubjects());
    }

    public function test_charges_once_invoice_control_is_released(): void
    {
        $this->maritimeExportBooking(carrierRequiresInvoice: true);
        $this->generate();

        $sent = app(ChargeInvoiceControls::class)([ControleInvoiceMaster::sole()], 'Liberado no teste.', self::USER);

        $this->assertSame(1, $sent);
        $this->assertSame(1, DB::table('fin_charges')->where('ref_booking', self::BOOKING)->count());
        $this->assertSame(InvoiceControlStatus::ReleasedAwaitingFile, ControleInvoiceMaster::sole()->status);
        $this->assertSame(1, (int) DB::table('cobranca_manual')->where('embarque_id', self::BOOKING)->value('control'));
    }

    private function generate(): int
    {
        return app(GenerateCharges::class)([[self::BOOKING, fn () => null]], null, self::USER);
    }

    /** EXPO marítima com um BL e uma despesa de processo em BRL (R$ 100, sem IOF), cliente sem prazo/boleto (sem NFS-e). */
    private function maritimeExportBooking(bool $carrierRequiresInvoice): void
    {
        DB::table('usuario')->insert(['usuario_id' => self::USER, 'nome' => 'Financeiro']);
        DB::table('pessoa')->insert(['pessoa_id' => 100, 'nome' => 'ACME EXPORTADORA']);
        DB::table('armador')->insert([
            'armador_id' => self::CARRIER,
            'nome' => 'HAPAG LLOYD',
            'invoice_impo' => 0,
            'invoice_expo' => (int) $carrierRequiresInvoice,
        ]);
        DB::table('embarque')->insert([
            'embarque_id' => self::BOOKING,
            'booking' => 'BKG1',
            'tipo_embarque' => 'm',
            'impor_expor' => 'e',
            'cliente_id' => 100,
            'armador_id' => self::CARRIER,
            'qtd_bl' => 1,
            'ctr_desp' => 1,
        ]);
        DB::table('bl')->insert(['bl_id' => self::BL, 'embarque_id' => self::BOOKING, 'bl' => 'HBL1', 'mbl_number' => 'MBL1']);
        DB::table('container')->insert(['bl_id' => self::BL]);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'BRL', 'symbol' => 'R$']);
        DB::table('fin_expenses')->insert(['id' => 500, 'name' => 'TAXA DE PROCESSO', 'ref_unity' => 5]);
        DB::table('fin_expenses_values')->insert([
            'ref_embarque' => self::BOOKING,
            'ref_expense' => 500,
            'selling_rate' => 100,
            'ref_currency_sr' => 1,
            'billing' => 'p',
        ]);
    }
}

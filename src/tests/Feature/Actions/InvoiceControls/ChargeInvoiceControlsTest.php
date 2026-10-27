<?php

namespace Tests\Feature\Actions\InvoiceControls;

use App\Actions\InvoiceControls\ChargeInvoiceControls;
use App\Enums\Cobranca\InvoiceControlStatus;
use App\Models\ControleInvoiceMaster;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ChargeInvoiceControlsTest extends TestCase
{
    use LegacySchema;

    public function test_releases_pending_control_and_records_manual_charge(): void
    {
        $control = $this->control(InvoiceControlStatus::Pending);

        $sent = app(ChargeInvoiceControls::class)([$control], 'Liberado no teste.', 7);

        $this->assertSame(1, $sent);
        $this->assertSame(InvoiceControlStatus::ReleasedAwaitingFile, $control->fresh()->status);
        $this->assertSame(['liberacao', 'cobranca'], $control->logs()->pluck('acao')->all());
        $this->assertSame(7, (int) DB::table('cobranca_manual')->where('embarque_id', 10)->value('created_by'));
    }

    public function test_keeps_status_of_control_that_is_already_ok(): void
    {
        $control = $this->control(InvoiceControlStatus::Ok);

        app(ChargeInvoiceControls::class)([$control], 'Liberado no teste.', 7);

        $this->assertSame(InvoiceControlStatus::Ok, $control->fresh()->status);
        $this->assertSame(['cobranca'], $control->logs()->pluck('acao')->all());
    }

    public function test_skips_booking_charged_manually_in_the_last_30_seconds(): void
    {
        $this->freezeTime();
        $control = $this->control(InvoiceControlStatus::Pending);
        DB::table('cobranca_manual')->insert(['embarque_id' => 10, 'created_at' => now()->subSeconds(29), 'created_by' => 7]);

        $sent = app(ChargeInvoiceControls::class)([$control], 'Liberado no teste.', 7);

        $this->assertSame(0, $sent);
        $this->assertSame(InvoiceControlStatus::Pending, $control->fresh()->status);
        $this->assertSame(1, DB::table('cobranca_manual')->count());
    }

    public function test_charges_again_after_the_30_second_window(): void
    {
        $this->freezeTime();
        $control = $this->control(InvoiceControlStatus::Pending);
        DB::table('cobranca_manual')->insert(['embarque_id' => 10, 'created_at' => now()->subSeconds(31), 'created_by' => 7]);

        $this->assertSame(1, app(ChargeInvoiceControls::class)([$control], 'Liberado no teste.', 7));
    }

    private function control(InvoiceControlStatus $status): ControleInvoiceMaster
    {
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG1', 'tipo_embarque' => 'm', 'impor_expor' => 'e']);

        return ControleInvoiceMaster::forceCreate([
            'embarque_id' => 10,
            'armador_id' => 19,
            'mbl_number' => 'MBL1',
            'status' => $status,
            'created_at' => now(),
            'created_by' => 1,
        ]);
    }
}

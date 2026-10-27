<?php

namespace Tests\Feature\Models;

use App\Enums\Cobranca\InvoiceControlStatus;
use App\Models\ControleInvoiceMaster;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ControleInvoiceMasterTest extends TestCase
{
    use LegacySchema;

    public function test_received_file_marks_control_ok_and_logs_previous_status(): void
    {
        $control = $this->control(InvoiceControlStatus::ReleasedAwaitingFile);

        $control->markReceived('271026930_invoice.pdf', 7);

        $control->refresh();
        $this->assertSame(InvoiceControlStatus::Ok, $control->status);
        $this->assertSame('271026930_invoice.pdf', $control->file_name);
        $this->assertNotNull($control->uploaded_at);
        $this->assertFalse($control->releasedWithoutFile());
        $log = $control->logs()->sole();
        $this->assertSame('upload', $log->acao);
        $this->assertSame(InvoiceControlStatus::ReleasedAwaitingFile, $log->status_anterior);
        $this->assertSame(InvoiceControlStatus::Ok, $log->status_novo);
        $this->assertSame(7, $log->created_by);
    }

    public function test_only_pending_control_is_released_for_billing(): void
    {
        $pending = $this->control(InvoiceControlStatus::Pending);
        $ok = $this->control(InvoiceControlStatus::Ok, 'MBL2');

        $this->assertTrue($pending->releaseForBilling('Liberado.', 7));
        $this->assertFalse($ok->releaseForBilling('Liberado.', 7));

        $this->assertSame(InvoiceControlStatus::ReleasedAwaitingFile, $pending->fresh()->status);
        $this->assertSame(0, $ok->logs()->count());
    }

    public function test_release_without_invoice_marks_ok_without_file_unless_already_ok(): void
    {
        $released = $this->control(InvoiceControlStatus::ReleasedAwaitingFile);
        $ok = $this->control(InvoiceControlStatus::Ok, 'MBL2');

        $this->assertTrue($released->releaseWithoutInvoice(7));
        $this->assertFalse($ok->releaseWithoutInvoice(7));

        $this->assertTrue($released->fresh()->releasedWithoutFile());
        $this->assertSame(0, $ok->logs()->count());
    }

    private function control(InvoiceControlStatus $status, string $mbl = 'MBL1'): ControleInvoiceMaster
    {
        return ControleInvoiceMaster::forceCreate([
            'embarque_id' => 10,
            'armador_id' => 19,
            'mbl_number' => $mbl,
            'status' => $status,
            'created_at' => now(),
            'created_by' => 1,
        ]);
    }
}

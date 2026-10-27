<?php

namespace Tests\Feature\Filament;

use App\Enums\Cobranca\InvoiceControlStatus;
use App\Filament\Resources\InvoiceControls\InvoiceControlResource;
use App\Filament\Resources\InvoiceControls\Pages\ListInvoiceControls;
use App\Filament\Resources\InvoiceControls\Pages\ViewInvoiceControl;
use App\Models\ControleInvoiceMaster;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InvoiceControlResourceTest extends TestCase
{
    use LegacySchema;

    public function test_forbids_user_without_financial_credential(): void
    {
        $this->actingAs($this->user('comercial'))
            ->get(InvoiceControlResource::getUrl())
            ->assertForbidden();
    }

    public function test_lists_controls_of_the_active_status_tab(): void
    {
        $this->actingAs($this->user());
        $pending = $this->control(InvoiceControlStatus::Pending, 'MBL1');
        $released = $this->control(InvoiceControlStatus::ReleasedAwaitingFile, 'MBL2');

        Livewire::test(ListInvoiceControls::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$released])
            ->set('activeTab', 'liberados')
            ->assertCanSeeTableRecords([$released])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_bulk_release_without_invoice_marks_released_controls_ok(): void
    {
        $this->actingAs($this->user());
        $released = $this->control(InvoiceControlStatus::ReleasedAwaitingFile, 'MBL2');

        Livewire::test(ListInvoiceControls::class)
            ->set('activeTab', 'liberados')
            ->callTableBulkAction('liberarSemInvoice', [$released])
            ->assertNotified('1 invoice(s) liberado(s) sem arquivo.');

        $this->assertTrue($released->fresh()->releasedWithoutFile());
    }

    public function test_bulk_release_is_offered_only_on_pending_tab(): void
    {
        $this->actingAs($this->user());

        Livewire::test(ListInvoiceControls::class)
            ->assertTableBulkActionVisible('liberar')
            ->assertTableBulkActionHidden('liberarSemInvoice')
            ->set('activeTab', 'liberados')
            ->assertTableBulkActionHidden('liberar')
            ->assertTableBulkActionVisible('liberarSemInvoice');
    }

    public function test_uploaded_invoice_file_is_stored_and_marks_control_ok(): void
    {
        Storage::fake('tracking_uploads');
        $this->travelTo('2026-10-27 09:05');
        $this->actingAs($this->user());
        $control = $this->control(InvoiceControlStatus::Pending, 'MBL1');

        Livewire::test(ViewInvoiceControl::class, ['record' => $control->getKey()])
            ->callAction('enviarArquivo', ['arquivo' => UploadedFile::fake()->create('Invoice Hapag.pdf', 10, 'application/pdf')])
            ->assertHasNoFormErrors();

        $control->refresh();
        $this->assertSame(InvoiceControlStatus::Ok, $control->status);
        $this->assertSame('271026905_Invoice_Hapag.pdf', $control->file_name);
        Storage::disk('tracking_uploads')->assertExists('controleinvoice/271026905_Invoice_Hapag.pdf');
    }

    public function test_rejects_invoice_file_of_unsupported_type(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user());
        $control = $this->control(InvoiceControlStatus::Pending, 'MBL1');

        Livewire::test(ViewInvoiceControl::class, ['record' => $control->getKey()])
            ->callAction('enviarArquivo', ['arquivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertHasFormErrors(['arquivo']);

        $this->assertSame(InvoiceControlStatus::Pending, $control->fresh()->status);
    }

    public function test_rejects_allowed_mime_type_with_executable_extension(): void
    {
        Storage::fake('tracking_uploads');
        $this->actingAs($this->user());
        $control = $this->control(InvoiceControlStatus::Pending, 'MBL1');

        Livewire::test(ViewInvoiceControl::class, ['record' => $control->getKey()])
            ->callAction('enviarArquivo', ['arquivo' => UploadedFile::fake()->create('invoice.php', 10, 'application/pdf')])
            ->assertHasFormErrors(['arquivo']);

        $this->assertSame([], Storage::disk('tracking_uploads')->allFiles('controleinvoice'));
    }

    public function test_release_without_invoice_is_hidden_once_control_is_ok(): void
    {
        $this->actingAs($this->user());
        $control = $this->control(InvoiceControlStatus::Ok, 'MBL1');

        Livewire::test(ViewInvoiceControl::class, ['record' => $control->getKey()])
            ->assertActionHidden('liberarSemInvoice');
    }

    private function user(string $credentials = 'financial'): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);

        return User::find($id);
    }

    private function control(InvoiceControlStatus $status, string $mbl): ControleInvoiceMaster
    {
        DB::table('embarque')->insertOrIgnore(['embarque_id' => 10, 'booking' => 'BKG1', 'tipo_embarque' => 'm', 'impor_expor' => 'e']);

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

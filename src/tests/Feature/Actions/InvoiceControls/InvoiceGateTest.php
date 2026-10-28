<?php

namespace Tests\Feature\Actions\InvoiceControls;

use App\Actions\Cobranca\Generation\GenerateCharges;
use App\Actions\InvoiceControls\InvoiceGate;
use App\Enums\Cobranca\InvoiceControlStatus;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use stdClass;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InvoiceGateTest extends TestCase
{
    use LegacySchema;

    public function test_does_not_block_extra_charge(): void
    {
        [$b, $bl] = $this->booking(invoiceExpo: 1);

        $this->assertFalse(app(InvoiceGate::class)->blocks($b, $bl, GenerateCharges::TYPE_EXTRA, 1));
        $this->assertSame(0, DB::table('controle_invoice_master')->count());
    }

    public function test_does_not_block_when_carrier_requires_invoice_only_on_the_other_operation(): void
    {
        [$b, $bl] = $this->booking(invoiceImpo: 1);

        $this->assertFalse(app(InvoiceGate::class)->blocks($b, $bl, GenerateCharges::TYPE_NORMAL, 1));
    }

    public function test_does_not_block_once_control_is_ok_or_released(): void
    {
        [$b, $bl] = $this->booking(invoiceExpo: 1);
        $id = $this->control($b, InvoiceControlStatus::Ok);
        $gate = app(InvoiceGate::class);

        $this->assertFalse($gate->blocks($b, $bl, GenerateCharges::TYPE_NORMAL, 1));

        DB::table('controle_invoice_master')->where('controle_invoice_master_id', $id)->update(['status' => InvoiceControlStatus::ReleasedAwaitingFile->value]);

        $this->assertFalse($gate->blocks($b, $bl, GenerateCharges::TYPE_NORMAL, 1));
    }

    public function test_keeps_blocking_existing_pending_control_without_duplicating_it(): void
    {
        [$b, $bl] = $this->booking(invoiceExpo: 1);
        $this->control($b, InvoiceControlStatus::Pending);

        $this->assertTrue(app(InvoiceGate::class)->blocks($b, $bl, GenerateCharges::TYPE_NORMAL, 1));
        $this->assertSame(1, DB::table('controle_invoice_master')->count());
    }

    public function test_digest_lists_controls_released_awaiting_file_even_without_blocks(): void
    {
        [$b] = $this->booking(invoiceExpo: 1);
        $this->control($b, InvoiceControlStatus::ReleasedAwaitingFile);

        app(InvoiceGate::class)->sendDigest();

        $this->assertSame(['DMF Logistics: Controle de Invoice: pendentes e aguardando arquivo'], $this->sentSubjects()->all());
    }

    public function test_digest_keeps_legacy_order_where_strtotime_reads_the_formatted_eta_as_month_first(): void
    {
        $this->booking(invoiceExpo: 1);
        DB::table('embarque')->where('embarque_id', 10)->update(['chegada_prevista' => '2026-12-25']);
        DB::table('embarque')->insert(['embarque_id' => 11, 'booking' => 'BKG2', 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'armador_id' => 19, 'chegada_prevista' => '2026-01-10']);
        foreach ([10 => 'MBLA', 11 => 'MBLB'] as $embarque => $mbl) {
            DB::table('controle_invoice_master')->insert([
                'embarque_id' => $embarque,
                'armador_id' => 19,
                'mbl_number' => $mbl,
                'status' => InvoiceControlStatus::ReleasedAwaitingFile->value,
                'created_at' => now(),
                'created_by' => 1,
            ]);
        }

        app(InvoiceGate::class)->sendDigest();

        $body = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();
        $this->assertLessThan(strpos($body, 'MBLB'), strpos($body, 'MBLA'));
    }

    public function test_digest_is_not_sent_when_nothing_is_pending(): void
    {
        app(InvoiceGate::class)->sendDigest();

        $this->assertSame([], $this->sentSubjects()->all());
    }

    /** @return array{0: Embarque, 1: stdClass} */
    private function booking(int $invoiceImpo = 0, int $invoiceExpo = 0): array
    {
        DB::table('armador')->insert(['armador_id' => 19, 'nome' => 'HAPAG', 'invoice_impo' => $invoiceImpo, 'invoice_expo' => $invoiceExpo]);
        DB::table('embarque')->insert(['embarque_id' => 10, 'booking' => 'BKG1', 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'armador_id' => 19]);
        DB::table('bl')->insert(['bl_id' => 20, 'embarque_id' => 10, 'bl' => 'HBL1', 'mbl_number' => ' MBL1 ']);

        return [Embarque::find(10), DB::table('bl')->where('bl_id', 20)->first()];
    }

    private function control(Embarque $b, InvoiceControlStatus $status): int
    {
        return DB::table('controle_invoice_master')->insertGetId([
            'embarque_id' => $b->embarque_id,
            'armador_id' => 19,
            'mbl_number' => 'MBL1',
            'status' => $status->value,
            'created_at' => now(),
            'created_by' => 1,
        ]);
    }
}

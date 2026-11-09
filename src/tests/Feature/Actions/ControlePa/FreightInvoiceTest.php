<?php

namespace Tests\Feature\Actions\ControlePa;

use App\Actions\ControlePa\DeleteFreightInvoice;
use App\Actions\ControlePa\GenerateFreightInvoice;
use App\Actions\ControlePa\SendFreightInvoice;
use App\Models\ControlePa;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FreightInvoiceTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tracking_uploads');
        $this->travelTo('2026-11-09 14:35:20');

        DB::table('paises')->insert(['paises_id' => 1, 'nome' => 'United States', 'uncode' => 'US']);
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'EXPORTADORA LTDA']);
        DB::table('pessoa')->insert(['pessoa_id' => 2, 'nome' => 'PAYER INC', 'endereco' => '1 MAIN ST', 'fone1' => '555', 'paises_id' => 1]);
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'USD']);
        DB::table('fin_expenses_unities')->insert([['id' => 1, 'name' => 'Processo', 'acronym' => 'PR'], ['id' => 2, 'name' => 'Container', 'acronym' => 'CT']]);
        DB::table('fin_expenses')->insert([['id' => 1, 'name' => 'OCEAN FREIGHT', 'ref_unity' => 2], ['id' => 2, 'name' => 'DOC FEE', 'ref_unity' => 1]]);
        DB::table('tipo_container')->insert(['tipo_container_id' => 1, 'nome' => '40HC']);
        DB::table('embarque')->insert([
            'embarque_id' => 10, 'booking' => 'BKG/10', 'impor_expor' => 'e', 'tipo_embarque' => 'm', 'flag_fi' => 1, 'recebedor_freight_invoice' => 'd',
            'pessoa_id_cliente' => 1, 'pessoa_id_pagador' => 2, 'saida_efetiva' => '2026-11-01', 'master_bl' => 'MBL1', 'bl_direto' => 1,
        ]);
        DB::table('mercadoria')->insert(['embarque_id' => 10, 'quantidade' => 2, 'tipo_container_id' => 1]);
        foreach ([[1, 1500, 'f', 1], [2, 50, 'f', null], [2, 999, 'p', null]] as [$expense, $rate, $billing, $equipment]) {
            DB::table('fin_expenses_values')->insert(['ref_expense' => $expense, 'ref_embarque' => 10, 'selling_rate' => $rate, 'ref_currency_sr' => 2, 'ref_equipment' => $equipment, 'billing' => $billing]);
        }
    }

    public function test_generates_the_pdf_attaches_it_and_opens_the_pa_control(): void
    {
        $control = app(GenerateFreightInvoice::class)(Embarque::find(10), 7);

        $file = DB::table('cmn_files')->sole();
        $this->assertSame('freight_invoice_BKG_10_091126143520.pdf', $file->name);
        $this->assertSame(['Documento Anexo a Reserva', sha1((string) $file->id), 7], [$file->subject, $file->uid, (int) $file->created_by]);
        $this->assertStringStartsWith('%PDF', Storage::disk('tracking_uploads')->get('docs_emb/'.$file->name));
        $this->assertSame([[10, 24, (int) $file->id]], DB::table('emb_embarque_files')->get()->map(fn ($l) => [(int) $l->ref_embarque, (int) $l->ref_file_type, (int) $l->ref_file])->all());
        $this->assertSame([10, 0], [(int) $control->embarque_id, (int) $control->flag_fi_dmf]);
    }

    public function test_refuses_a_booking_that_cannot_generate_without_creating_anything(): void
    {
        foreach ([
            ['flag_fi' => 0, 'A reserva não está marcada com Freight Invoice.'],
            ['recebedor_freight_invoice' => null, 'É necessário selecionar o recebedor do pagamento para gerar o documento!'],
            ['pessoa_id_pagador' => null, 'É necessário informar o pagador ou o importador da reserva.'],
        ] as $case) {
            $message = array_pop($case);
            DB::table('embarque')->where('embarque_id', 10)->update($case);

            try {
                app(GenerateFreightInvoice::class)(Embarque::find(10), 7);
                $this->fail($message);
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }

            DB::table('embarque')->where('embarque_id', 10)->update(['flag_fi' => 1, 'recebedor_freight_invoice' => 'd', 'pessoa_id_pagador' => 2]);
        }

        $this->assertSame(0, DB::table('cmn_files')->count() + ControlePa::count());
    }

    public function test_second_generation_is_refused_while_a_control_is_open(): void
    {
        app(GenerateFreightInvoice::class)(Embarque::find(10), 7);

        $this->expectExceptionMessage('A Freight Invoice desta reserva já foi gerada.');

        app(GenerateFreightInvoice::class)(Embarque::find(10), 7);
    }

    public function test_send_emails_the_attachment_once_and_marks_the_control_as_sent(): void
    {
        $control = app(GenerateFreightInvoice::class)(Embarque::find(10), 7);
        $link = EmbEmbarqueFile::first();

        app(SendFreightInvoice::class)($link, 'Ana', 'ana@dmflog.com.br');

        $this->assertSame(['[PREPAID ABROAD] Freight Invoice BKG/10'], $this->sentSubjects()->all());
        $this->assertSame(1, (int) $control->fresh()->flag_fi_dmf);

        try {
            app(SendFreightInvoice::class)($link, 'Ana', null);
            $this->fail('Já foi enviada.');
        } catch (DomainException) {
            $this->assertCount(1, $this->sentSubjects());
        }
    }

    public function test_send_does_not_downgrade_a_confirmed_shipment(): void
    {
        $control = app(GenerateFreightInvoice::class)(Embarque::find(10), 7);
        $control->forceFill(['flag_fi_dmf' => 2])->save();

        $this->expectException(DomainException::class);

        app(SendFreightInvoice::class)(EmbEmbarqueFile::first(), 'Ana', null);
    }

    public function test_delete_unlinks_the_invoice_and_drops_the_control_but_keeps_the_file(): void
    {
        app(GenerateFreightInvoice::class)(Embarque::find(10), 7);

        app(DeleteFreightInvoice::class)(EmbEmbarqueFile::first());

        $this->assertSame([0, 0, 1], [EmbEmbarqueFile::count(), ControlePa::count(), DB::table('cmn_files')->count()]);
        $this->assertNotNull(app(GenerateFreightInvoice::class)(Embarque::find(10), 7));
    }
}

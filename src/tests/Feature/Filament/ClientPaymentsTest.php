<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ClientShipmentCostsPdf;
use App\Actions\Financeiro\ClientShipmentCostsReport;
use App\Filament\Pages\ClientPayments;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ClientPaymentsTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    private const PERIOD = ['cliente_id' => 7, 'data_ini' => '2026-10-01', 'data_fim' => '2026-10-31'];

    public function test_manager_and_financial_open_the_page_and_others_do_not(): void
    {
        $this->actingAs($this->user('booking'))->get(ClientPayments::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(ClientPayments::getUrl())->assertOk();
        $this->actingAs($this->user('financial'))->get(ClientPayments::getUrl())->assertOk();
    }

    public function test_lists_charges_paid_in_the_period_and_open_ones_of_any_date_for_that_client_only(): void
    {
        $this->seedCharges();

        $report = app(ClientShipmentCostsReport::class)(self::PERIOD);

        $this->assertSame('Cliente Sete', $report['client']);
        $this->assertSame(['BKG-ABERTA', 'BKG-PAGA'], array_column($report['shipments'], 'booking'));

        $paid = $report['shipments'][1];
        $this->assertSame('Exportação', $paid['type']);
        $this->assertSame('Santos (Brasil)', $paid['origin']);
        $this->assertSame('2 x 40HC, 1 x 20DC', $paid['containers']);
        $this->assertSame(105.0, $paid['total']);
        $this->assertSame(5.0, $paid['charges'][0]['iof']);
        $this->assertStringStartsWith('TAXA 500', $paid['charges'][0]['items'][0]['name']);
        $this->assertSame(100.0, $paid['charges'][0]['items'][0]['value']);
        $this->assertSame(['name' => 'Agente Norte', 'address' => 'Rua 1', 'country' => 'Chile (CL)', 'cnpj' => '123'], $paid['agent']);

        $this->assertNull($report['shipments'][0]['agent']);
    }

    public function test_filters_by_carrier(): void
    {
        $this->seedCharges();

        $report = app(ClientShipmentCostsReport::class)([...self::PERIOD, 'armador_id' => 99]);

        $this->assertSame([], $report['shipments']);
    }

    public function test_pdf_is_generated_with_every_section(): void
    {
        $this->seedCharges();
        $report = app(ClientShipmentCostsReport::class)(self::PERIOD);

        $pdf = app(ClientShipmentCostsPdf::class)->render($report, '2026-10-01', '2026-10-31');

        $this->assertStringStartsWith('%PDF', $pdf->Output('', 'S'));
    }

    public function test_page_downloads_the_pdf_or_warns_when_nothing_matches(): void
    {
        $this->actingAs($this->user('financial'));
        $this->seedCharges();

        Livewire::test(ClientPayments::class)
            ->fillForm(self::PERIOD)
            ->call('send')
            ->assertFileDownloaded('custos-embarques.pdf')
            ->fillForm([...self::PERIOD, 'armador_id' => 99])
            ->call('send')
            ->assertNotified('Nenhuma cobrança encontrada para os filtros informados.')
            ->fillForm([...self::PERIOD, 'data_ini' => '2026-11-01'])
            ->call('send')
            ->assertHasFormErrors(['data_fim']);
    }

    private function seedCharges(): void
    {
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'BRL', 'symbol' => 'R$']);
        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'Brasil', 'uncode' => 'BR'], ['paises_id' => 2, 'nome' => 'Chile', 'uncode' => 'CL']]);
        DB::table('porto')->insert(['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1]);
        DB::table('pessoa')->insert([
            ['pessoa_id' => 7, 'nome' => 'Cliente Sete'],
            ['pessoa_id' => 8, 'nome' => 'Outro Cliente'],
        ]);
        DB::table('pessoa')->insert(['pessoa_id' => 20, 'nome' => 'Agente Norte', 'endereco' => 'Rua 1', 'cnpj' => '123', 'paises_id' => 2]);
        DB::table('armador')->insert([['armador_id' => 5, 'nome' => 'Maersk'], ['armador_id' => 99, 'nome' => 'Hapag']]);
        DB::table('tipo_container')->insert([['tipo_container_id' => 1, 'nome' => '40HC'], ['tipo_container_id' => 2, 'nome' => '20DC']]);

        $paid = $this->charge(['payment_date' => '2026-10-10', 'status' => 1, 'valor_iof' => 5], ['embarque_id' => 1, 'booking' => 'BKG-PAGA', 'cliente_id' => 7, 'porto_origem_id' => 1, 'armador_id' => 5, 'pessoa_id_representante' => 20, 'impor_expor' => 'e']);
        $this->item($paid, 100.0, 1, 500, 100.0);
        DB::table('mercadoria')->insert([['embarque_id' => 1, 'quantidade' => 2, 'tipo_container_id' => 1], ['embarque_id' => 1, 'quantidade' => 1, 'tipo_container_id' => 2]]);

        $this->charge(['payment_date' => '2026-01-05', 'status' => 1], ['embarque_id' => 2, 'booking' => 'BKG-ANTIGA', 'cliente_id' => 7]);
        $this->charge(['payment_date' => null, 'status' => 0], ['embarque_id' => 3, 'booking' => 'BKG-ABERTA', 'cliente_id' => 7, 'armador_id' => 5]);
        $this->charge(['payment_date' => null, 'status' => 3], ['embarque_id' => 4, 'booking' => 'BKG-BAIXA-MANUAL', 'cliente_id' => 7]);
        $this->charge(['payment_date' => '2026-10-10', 'status' => 1], ['embarque_id' => 5, 'booking' => 'BKG-OUTRO', 'cliente_id' => 8]);
    }
}

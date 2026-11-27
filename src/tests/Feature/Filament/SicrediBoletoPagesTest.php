<?php

namespace Tests\Feature\Filament;

use App\Actions\Cobranca\Boleto\IssueBoleto;
use App\Filament\Resources\Charges\Pages\ListCharges;
use App\Filament\Resources\Charges\Pages\ViewCharge;
use App\Filament\Resources\EmailTexts\EmailTextResource;
use App\Filament\Resources\EmailTexts\Pages\ManageEmailTexts;
use App\Models\FinCharges;
use App\Models\FinEmailTexto;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsBoletos;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class SicrediBoletoPagesTest extends TestCase
{
    use BuildsBoletos, BuildsCharges, LegacySchema;

    private FinCharges $charge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sicredi();
        $this->charge = $this->boletoCharge();
        $this->actingAs($this->user('financial'));
    }

    public function test_issues_from_the_charge_page_and_shows_the_boleto(): void
    {
        Livewire::test(ViewCharge::class, ['record' => $this->charge->id])
            ->assertActionVisible('gerarBoletoSicredi')
            ->callAction('gerarBoletoSicredi', ['due_date' => today()->addDays(3)->toDateString()])
            ->assertHasNoActionErrors();

        Livewire::test(ViewCharge::class, ['record' => $this->charge->id])
            ->assertSee(['Boleto Sicredi', '211001234', 'R$ 995,00', 'Baixar boleto']);
    }

    public function test_client_without_boleto_has_no_button(): void
    {
        DB::table('fin_clients_boleto')->update(['boleto_imp' => 0]);

        Livewire::test(ViewCharge::class, ['record' => $this->charge->id])
            ->assertActionHidden('gerarBoletoSicredi')
            ->assertDontSee('Boleto Sicredi');
    }

    public function test_cancel_request_from_the_charge_page(): void
    {
        app(IssueBoleto::class)($this->charge, 1);

        Livewire::test(ViewCharge::class, ['record' => $this->charge->id])
            ->callAction(TestAction::make('pedirBaixaBoleto')->schemaComponent('boleto_sicredi', schema: 'infolist'))
            ->assertNotified('Pedido de baixa enviado à Sicredi.');

        $this->assertDatabaseHas('fin_charges_boleto_sicredi', ['status' => 'baixado']);
    }

    public function test_list_filters_charges_by_boleto_client(): void
    {
        $without = $this->charge([], ['embarque_id' => 11, 'impor_expor' => 'e', 'cliente_id' => 20]);

        Livewire::test(ListCharges::class)
            ->filterTable('busca', ['boleto' => '1'])
            ->assertCanSeeTableRecords([$this->charge])
            ->assertCanNotSeeTableRecords([$without])
            ->filterTable('busca', ['boleto' => '0'])
            ->assertCanSeeTableRecords([$without])
            ->assertCanNotSeeTableRecords([$this->charge]);
    }

    public function test_email_texts_are_edited_and_previewed(): void
    {
        $id = DB::table('fin_email_texto')->insertGetId(['mnemonico' => 'IC_EMAIL_IMPO', 'corpo' => '<font>antigo</font>', 'ativo' => 1]);

        Livewire::test(ManageEmailTexts::class)
            ->callTableAction('edit', $id, ['corpo' => '<font color="red">reserva {{booking}}</font>'])
            ->assertHasNoTableActionErrors()
            ->mountAction('preview')
            ->setActionData(['charge' => $this->charge->id])
            ->assertMountedActionModalSee('reserva BKG10');

        $this->assertSame(['<font color="red">reserva {{booking}}</font>', 'IC_EMAIL_IMPO'], [FinEmailTexto::find($id)->corpo, FinEmailTexto::find($id)->mnemonico]);
    }

    public function test_email_texts_require_the_financial_credential(): void
    {
        $this->actingAs($this->user('comercial'))->get(EmailTextResource::getUrl())->assertForbidden();
    }
}

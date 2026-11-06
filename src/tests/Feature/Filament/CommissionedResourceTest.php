<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ShippingCommissions;
use App\Filament\Resources\Commissioned\Pages\ManageCommissioned;
use App\Models\ComissaoEmbarqueResponsavel;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CommissionedResourceTest extends TestCase
{
    use LegacySchema;

    public function test_lists_commissioned_with_their_open_commissions_and_creates_new_ones(): void
    {
        $this->actingAs($this->user('financial'));
        $ana = ComissaoEmbarqueResponsavel::create(['nome' => 'Ana']);
        $this->commission($ana, status: 0);
        $this->commission($ana, status: null);
        $this->commission($ana, status: 1);

        Livewire::test(ManageCommissioned::class)
            ->assertCanSeeTableRecords([$ana])
            ->assertTableColumnStateSet('open_count', 2, $ana)
            ->callAction('create', ['nome' => 'Beto', 'dados_bancarios' => 'Banco 1'])
            ->assertHasNoFormErrors();

        $this->assertSame('Banco 1', ComissaoEmbarqueResponsavel::where('nome', 'Beto')->value('dados_bancarios'));
    }

    public function test_commissioned_with_commissions_cannot_be_deleted(): void
    {
        $this->actingAs($this->user('financial'));
        $used = ComissaoEmbarqueResponsavel::create(['nome' => 'Usado']);
        $free = ComissaoEmbarqueResponsavel::create(['nome' => 'Livre']);
        $this->commission($used);

        Livewire::test(ManageCommissioned::class)
            ->assertTableActionHidden(DeleteAction::class, $used)
            ->callTableAction(DeleteAction::class, $free);

        $this->assertNull($free->fresh());
    }

    public function test_opening_from_the_list_filters_the_report_without_the_date_cut(): void
    {
        $this->actingAs($this->user('financial'));
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Real', 'symbol' => 'R$']);
        $ana = ComissaoEmbarqueResponsavel::create(['nome' => 'Ana']);
        $beto = ComissaoEmbarqueResponsavel::create(['nome' => 'Beto']);
        $this->commission($ana, booking: 'ANA-OLD', createdAt: '2020-01-01 00:00:00');
        $this->commission($beto, booking: 'BETO-1');

        $this->get(ShippingCommissions::getUrl(['responsavel_id' => $ana->getKey()]))
            ->assertOk()
            ->assertSee('ANA-OLD')
            ->assertDontSee('BETO-1');
    }

    public function test_requires_the_financial_credential(): void
    {
        $this->actingAs($this->user('booking'));

        Livewire::test(ManageCommissioned::class)->assertForbidden();
    }

    private function commission(ComissaoEmbarqueResponsavel $person, ?int $status = 0, string $booking = 'B', string $createdAt = '2026-02-01 00:00:00'): void
    {
        DB::table('comissao_embarque')->insert([
            'comissao_embarque_responsavel_id' => $person->getKey(), 'embarque_id' => DB::table('embarque')->insertGetId(['booking' => $booking]),
            'moeda_id' => 1, 'valor_unitario' => 10, 'created_at' => $createdAt, 'created_by' => 1, 'status' => $status,
        ]);
    }

    private function user(string $credentials): User
    {
        return User::forceCreate(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]);
    }
}

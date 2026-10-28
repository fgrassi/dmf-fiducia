<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Carriers\Pages\ViewCarrier;
use App\Filament\Resources\Carriers\RelationManagers\BanksRelationManager;
use App\Filament\Resources\Carriers\RelationManagers\GuaranteeDepositsRelationManager;
use App\Filament\Resources\Carriers\RelationManagers\PortsRelationManager;
use App\Models\Armador;
use App\Models\CmnArmadorPorto;
use App\Models\FinArmadorBanks;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CarrierRelationsTest extends TestCase
{
    use LegacySchema;

    private Armador $carrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->user());
        $this->carrier = Armador::create(['nome' => 'Hapag']);
    }

    public function test_adds_port_once_and_lists_by_name(): void
    {
        $santos = DB::table('porto')->insertGetId(['nome' => 'Santos']);

        $manager = $this->manager(PortsRelationManager::class)
            ->callAction(TestAction::make('create')->table(), ['ref_porto' => $santos])
            ->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['ref_porto' => $santos])
            ->assertHasFormErrors(['ref_porto']);

        $this->assertSame(1, $this->carrier->ports()->count());
        $manager->assertSee('Santos');
    }

    public function test_port_with_matrix_cannot_be_removed(): void
    {
        $port = CmnArmadorPorto::create(['ref_armador' => $this->carrier->armador_id, 'ref_porto' => DB::table('porto')->insertGetId(['nome' => 'Santos'])]);
        $free = CmnArmadorPorto::create(['ref_armador' => $this->carrier->armador_id, 'ref_porto' => DB::table('porto')->insertGetId(['nome' => 'Itajaí'])]);
        DB::table('fin_expenses_matrix')->insert(['ref_armador_porto' => $port->id, 'ref_expense' => 1, 'type' => 'i', 'expense_value' => 1, 'imo_value' => 0, 'ref_currency' => 1, 'billing' => 'p', 'due_from' => '2026-01-01', 'created_by' => 1]);

        $this->manager(PortsRelationManager::class)
            ->assertTableActionHidden(DeleteAction::class, $port)
            ->assertTableActionVisible(DeleteAction::class, $free);
    }

    public function test_bank_is_audited_soft_deleted_and_disabled_accounts_are_hidden(): void
    {
        $bank = DB::table('cmn_banks')->insertGetId(['name' => 'Itaú', 'created_by' => 1]);

        $this->manager(BanksRelationManager::class)
            ->callAction(TestAction::make('create')->table(), ['ref_bank' => $bank, 'bank_agency' => '0001', 'bank_account' => '123-4'])
            ->assertHasNoFormErrors();

        $account = FinArmadorBanks::sole();
        $this->assertNotNull($account->created_by);

        $disabled = FinArmadorBanks::create(['ref_armador' => $this->carrier->armador_id, 'ref_bank' => $bank, 'created_by' => 1, 'bank_account' => 'x', 'bank_agency' => 'y']);
        DB::table('fin_armador_banks')->where('id', $disabled->id)->update(['id' => FinArmadorBanks::DISABLED_IDS[0]]);

        $this->manager(BanksRelationManager::class)
            ->assertCountTableRecords(1)
            ->callTableAction(DeleteAction::class, $account)
            ->assertCountTableRecords(0);

        $this->assertNotNull($account->fresh()->deleted_at);
        $this->assertNotNull($account->fresh()->deleted_by);
    }

    public function test_bank_requires_agency_and_account(): void
    {
        $bank = DB::table('cmn_banks')->insertGetId(['name' => 'Itaú', 'created_by' => 1]);

        $this->manager(BanksRelationManager::class)
            ->callAction(TestAction::make('create')->table(), ['ref_bank' => $bank])
            ->assertHasFormErrors(['bank_agency' => 'required', 'bank_account' => 'required']);
    }

    public function test_guarantee_deposit_is_added_and_deleted(): void
    {
        $container = DB::table('tipo_container')->insertGetId(['nome' => "40' HC"]);
        $currency = DB::table('cmn_currencies')->insertGetId(['name' => 'Dólar', 'symbol' => 'USD']);

        $manager = $this->manager(GuaranteeDepositsRelationManager::class)
            ->callAction(TestAction::make('create')->table(), ['ref_equipment' => $container, 'valor' => '1500', 'ref_currency' => $currency])
            ->assertHasNoFormErrors();

        $manager->assertSee('USD 1.500,00');

        $manager->callTableAction(DeleteAction::class, $this->carrier->guaranteeDeposits()->sole());
        $this->assertSame(0, $this->carrier->guaranteeDeposits()->count());
    }

    private function manager(string $class)
    {
        return Livewire::test($class, ['ownerRecord' => $this->carrier, 'pageClass' => ViewCarrier::class]);
    }

    private function user(): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'auxiliares']));
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\CommissionsRelationManager;
use App\Models\ComissaoEmbarque;
use App\Models\Embarque;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CommissionsRelationManagerTest extends TestCase
{
    use LegacySchema;

    public function test_adds_and_removes_a_commission_of_the_booking(): void
    {
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking'])));
        DB::table('cmn_currencies')->insert(['id' => 2, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('comissao_embarque_responsavel')->insert(['comissao_embarque_responsavel_id' => 1, 'nome' => 'Ana Comissionada']);
        $booking = Embarque::find(DB::table('embarque')->insertGetId(['booking' => 'BKG-1']));

        Livewire::test(CommissionsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->callAction(TestAction::make('create')->table(), ['responsavel_id' => 1, 'tipo_comissao' => 1, 'tipo' => 1, 'moeda_id' => 2, 'valor_unitario' => 55.5])
            ->assertHasNoFormErrors();

        $commission = ComissaoEmbarque::query()->firstOrFail();
        $this->assertSame([1, 1, 2], [(int) $commission->tipo_comissao, (int) $commission->tipo, (int) $commission->moeda_id]);
        $this->assertSame('Comissão adicionada', DB::table('rastreamento')->value('assunto'));

        Livewire::test(CommissionsRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertCanSeeTableRecords([$commission])
            ->assertTableColumnFormattedStateSet('valor_unitario', '55,50', $commission)
            ->callAction(TestAction::make('delete')->table($commission));

        $this->assertSame(0, ComissaoEmbarque::count());
        $this->assertSame(['Comissão adicionada', 'Comissão removida'], DB::table('rastreamento')->orderBy('id')->pluck('assunto')->all());
    }
}

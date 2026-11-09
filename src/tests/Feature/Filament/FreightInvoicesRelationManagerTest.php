<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ViewEmbarque;
use App\Filament\Resources\Embarques\RelationManagers\FreightInvoicesRelationManager;
use App\Models\ControlePa;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class FreightInvoicesRelationManagerTest extends TestCase
{
    use LegacySchema;

    public function test_tab_only_exists_for_export_bookings(): void
    {
        $this->assertTrue(FreightInvoicesRelationManager::canViewForRecord($this->booking(['impor_expor' => 'e']), ViewEmbarque::class));
        $this->assertFalse(FreightInvoicesRelationManager::canViewForRecord($this->booking(['impor_expor' => 'i']), ViewEmbarque::class));
    }

    public function test_creates_sends_and_deletes_the_freight_invoice_from_the_tab(): void
    {
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'email' => 'ana@dmflog.com.br', 'credentials' => 'booking'])));
        Storage::fake('tracking_uploads');
        DB::table('pessoa')->insert(['pessoa_id' => 2, 'nome' => 'PAYER INC']);
        $booking = $this->booking(['impor_expor' => 'e', 'flag_fi' => 1, 'recebedor_freight_invoice' => 'd', 'pessoa_id_pagador' => 2]);
        $test = fn () => Livewire::test(FreightInvoicesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class]);

        $test()
            ->assertActionVisible(TestAction::make('criar')->table())
            ->callAction(TestAction::make('criar')->table())
            ->assertNotified('Freight Invoice criada.')
            ->assertActionHidden(TestAction::make('criar')->table());

        $link = EmbEmbarqueFile::sole();
        $this->assertSame(0, (int) ControlePa::sole()->flag_fi_dmf);

        $test()
            ->assertCanSeeTableRecords([$link])
            ->callAction(TestAction::make('enviar')->table($link))
            ->assertNotified('Freight Invoice enviada.')
            ->assertActionHidden(TestAction::make('enviar')->table($link));

        $this->assertSame(1, (int) ControlePa::sole()->flag_fi_dmf);

        $test()->callAction(TestAction::make('excluir')->table($link));

        $this->assertSame([0, 0], [EmbEmbarqueFile::count(), ControlePa::count()]);
    }

    public function test_hides_create_when_the_booking_has_no_freight_invoice(): void
    {
        $this->actingAs(User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking'])));
        $booking = $this->booking(['impor_expor' => 'e', 'flag_fi' => 0]);

        Livewire::test(FreightInvoicesRelationManager::class, ['ownerRecord' => $booking, 'pageClass' => ViewEmbarque::class])
            ->assertActionHidden(TestAction::make('criar')->table());
    }

    /** @param  array<string, mixed>  $attributes */
    private function booking(array $attributes): Embarque
    {
        return Embarque::find(DB::table('embarque')->insertGetId(['booking' => 'BKG-1', 'tipo_embarque' => 'm', ...$attributes]));
    }
}

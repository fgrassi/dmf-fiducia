<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\InvoiceCarriers\Pages\ManageInvoiceCarriers;
use App\Models\Armador;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InvoiceCarrierResourceTest extends TestCase
{
    use LegacySchema;

    public function test_includes_carrier_for_the_chosen_operations(): void
    {
        $this->actingAs($this->user());
        $carrier = $this->carrier();

        Livewire::test(ManageInvoiceCarriers::class)
            ->callAction('incluir', ['armador_id' => $carrier->armador_id, 'operacoes' => ['invoice_expo']])
            ->assertHasNoFormErrors();

        $this->assertSame([0, 1], array_map('intval', array_values($carrier->fresh()->only(['invoice_impo', 'invoice_expo']))));
    }

    public function test_rejects_inclusion_without_any_operation(): void
    {
        $this->actingAs($this->user());
        $carrier = $this->carrier();

        Livewire::test(ManageInvoiceCarriers::class)
            ->callAction('incluir', ['armador_id' => $carrier->armador_id, 'operacoes' => []])
            ->assertHasFormErrors(['operacoes' => 'Selecione ao menos uma operação.']);
    }

    public function test_lists_only_carriers_with_invoice_control_and_removes_them(): void
    {
        $this->actingAs($this->user());
        $enabled = $this->carrier(['nome' => 'HAPAG', 'invoice_impo' => 1]);
        $disabled = $this->carrier(['nome' => 'MSC']);

        Livewire::test(ManageInvoiceCarriers::class)
            ->assertCanSeeTableRecords([$enabled])
            ->assertCanNotSeeTableRecords([$disabled])
            ->callTableAction('remover', $enabled);

        $this->assertSame(0, (int) $enabled->fresh()->invoice_impo);
    }

    private function user(): User
    {
        $id = DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'financial']);

        return User::find($id);
    }

    private function carrier(array $attributes = []): Armador
    {
        return Armador::forceCreate(['nome' => 'HAPAG', 'invoice_impo' => 0, 'invoice_expo' => 0, ...$attributes]);
    }
}

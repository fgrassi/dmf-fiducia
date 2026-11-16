<?php

namespace Tests\Feature\Filament;

use App\Actions\Reports\PendingNotesPdf;
use App\Actions\Reports\PendingNotesReport;
use App\Filament\Pages\PendingNotes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class PendingNotesTest extends TestCase
{
    use LegacySchema;

    public function test_only_booking_opens_the_page(): void
    {
        $this->actingAs($this->user('financial'))->get(PendingNotes::getUrl())->assertForbidden();
        $this->actingAs($this->user('booking'))->get(PendingNotes::getUrl())->assertOk();
    }

    public function test_lists_export_sea_bookings_without_notes_with_their_negotiated_freight(): void
    {
        $this->actingAs($this->user('booking'));
        DB::table('pessoa')->insert(['pessoa_id' => 1, 'nome' => 'Shipper A']);
        DB::table('navio')->insert(['navio_id' => 1, 'nome' => 'MSC Anna']);
        DB::table('paises')->insert([['paises_id' => 1, 'nome' => 'Brasil'], ['paises_id' => 2, 'nome' => 'Holanda']]);
        DB::table('porto')->insert([['porto_id' => 1, 'nome' => 'Santos', 'ref_pais' => 1], ['porto_id' => 2, 'nome' => 'Rotterdam', 'ref_pais' => 2]]);
        DB::table('fin_expenses_unities')->insert(['id' => 1, 'name' => 'Por BL', 'acronym' => 'BL']);
        DB::table('fin_expenses')->insert([['id' => 10, 'name' => 'Ocean Freight', 'ref_unity' => 1], ['id' => 703, 'name' => 'Handling', 'ref_unity' => 1], ['id' => 11, 'name' => 'Outra', 'ref_unity' => 1]]);
        DB::table('regra_despesa_cobranca_item')->insert([['despesa_id' => 10, 'regra_despesa_cobranca_id' => 4], ['despesa_id' => 11, 'regra_despesa_cobranca_id' => 5]]);

        $base = ['pessoa_id_cliente' => 1, 'navio_id' => 1, 'porto_origem_id' => 1, 'porto_destino_id' => 2, 'tipo_embarque' => 'm', 'impor_expor' => 'e', 'saida_efetiva' => '2026-11-01'];
        foreach ([
            ['embarque_id' => 1, 'booking' => 'BKG-OK', 'bl_direto' => 1],
            ['embarque_id' => 2, 'booking' => 'BKG-DEBIT'],
            ['embarque_id' => 3, 'booking' => 'BKG-CREDIT'],
            ['embarque_id' => 4, 'booking' => 'BKG-CONTROL', 'controle_debit' => 1],
            ['embarque_id' => 5, 'booking' => 'BKG-IMPO', 'impor_expor' => 'i'],
            ['embarque_id' => 6, 'booking' => 'BKG-AIR', 'tipo_embarque' => 'a'],
            ['embarque_id' => 7, 'booking' => 'BKG-OLD', 'saida_efetiva' => '2019-12-31'],
            ['embarque_id' => 8, 'booking' => 'BKG-NOT-SAILED', 'saida_efetiva' => null],
            ['embarque_id' => 9, 'booking' => 'BKG-RECENT', 'saida_efetiva' => now()->subDays(2)->toDateString()],
        ] as $booking) {
            DB::table('embarque')->insert([...$base, ...$booking]);
        }
        DB::table('fin_debit_notes')->insert(['ref_embarque' => 2, 'ref_agente' => 1, 'created_at' => '2026-11-02', 'created_by' => 1, 'moeda_id' => 1, 'name' => 'D1', 'ref_file' => 1]);
        DB::table('fin_credit_notes')->insert(['ref_embarque' => 3, 'ref_agente' => 1, 'created_at' => '2026-11-02', 'created_by' => 1, 'moeda_id' => 1, 'name' => 'C1', 'ref_file' => 1]);
        DB::table('fin_expenses_values')->insert([
            ['ref_expense' => 10, 'ref_embarque' => 1, 'billing' => 'p'],
            ['ref_expense' => 703, 'ref_embarque' => 1, 'billing' => 'c'],
            ['ref_expense' => 11, 'ref_embarque' => 1, 'billing' => 'p'], // regra 5: não é frete
        ]);

        $rows = app(PendingNotesReport::class)();

        $this->assertSame(['BKG-OK', 'BKG-RECENT'], array_column($rows, 'booking'));
        $this->assertSame([
            'id' => 1, 'booking' => 'BKG-OK', 'direct_bl' => 'SIM', 'freight' => ['Ocean Freight/BL - PP', 'Handling/BL - CC'],
            'shipper' => 'Shipper A', 'vessel' => 'MSC Anna', 'origin' => 'Santos (Brasil)', 'destination' => 'Rotterdam (Holanda)', 'departed' => '2026-11-01',
        ], $rows[0]);
        $this->assertSame('NÃO', $rows[1]['direct_bl']);

        // O PDF só leva as embarcadas há mais de 5 dias.
        $this->assertSame(['BKG-OK'], array_column(app(PendingNotesReport::class)(now()->subDays(5)->toDateString()), 'booking'));
        $this->assertStringStartsWith('%PDF', app(PendingNotesPdf::class)->render($rows)->Output('', 'S'));

        Livewire::test(PendingNotes::class)
            ->assertSee(['BKG-OK', 'BKG-RECENT', 'SIM', 'Ocean Freight/BL - PP', 'Shipper A', 'MSC Anna', 'Santos (Brasil)', 'Rotterdam (Holanda)', '01/11/2026'])
            ->assertDontSee(['BKG-DEBIT', 'BKG-CREDIT', 'BKG-CONTROL', 'BKG-IMPO', 'BKG-AIR', 'BKG-OLD', 'BKG-NOT-SAILED'])
            ->callAction('pdf')
            ->assertFileDownloaded('pendencias-debit-credit-notes.pdf');
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

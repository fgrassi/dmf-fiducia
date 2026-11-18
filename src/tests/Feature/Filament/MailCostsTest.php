<?php

namespace Tests\Feature\Filament;

use App\Actions\Reports\MailCostsPdf;
use App\Actions\Reports\MailCostsReport;
use App\Filament\Pages\MailCosts;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class MailCostsTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
    }

    public function test_only_auxiliares_opens_the_page(): void
    {
        $this->actingAs($this->user('financial'))->get(MailCosts::getUrl())->assertForbidden();
        $this->actingAs($this->user('auxiliares'))->get(MailCosts::getUrl())->assertOk();
    }

    public function test_splits_the_value_between_the_bookings_and_totals_each_list(): void
    {
        DB::table('embarque')->insert([['embarque_id' => 1, 'booking' => 'BKG-1'], ['embarque_id' => 2, 'booking' => 'BKG-2']]);
        $this->mailing(1, 'SEDEX-A', '2026-11-05', 100, bookings: [1, 2]);
        $this->mailing(2, 'SEDEX-B', '2026-11-06', 50, bookings: [1]);
        $this->mailing(3, 'SEDEX-FORM', '2026-11-07', 30, form: true);
        $this->mailing(4, 'SEDEX-VAZIO', '2026-11-07', 99);
        $this->mailing(5, 'SEDEX-FORA', '2026-10-31', 77, bookings: [1]);

        $report = app(MailCostsReport::class)(['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30']);

        $this->assertSame(['SEDEX-FORM', 'SEDEX-B', 'SEDEX-A'], array_column($report['rows'], 'num_sedex'));
        $this->assertSame(50.0, $report['rows'][2]['value_per_booking']);
        $this->assertSame([150.0, 30.0, 180.0], [$report['linked_total'], $report['form_total'], $report['total']]);
    }

    public function test_a_form_mailing_with_bookings_counts_in_both_lists(): void
    {
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG-1']);
        $this->mailing(1, 'SEDEX-A', '2026-11-05', 40, bookings: [1], form: true);

        $report = app(MailCostsReport::class)(['data_ini' => '2026-11-01', 'data_fim' => '2026-11-30']);

        $this->assertCount(1, $report['rows']);
        $this->assertSame([40.0, 40.0, 80.0], [$report['linked_total'], $report['form_total'], $report['total']]);
    }

    public function test_opens_on_today_and_shows_rows_totals_and_the_pdf(): void
    {
        $this->actingAs($this->user('auxiliares'));
        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG-1']);
        $this->mailing(1, 'SEDEX-HOJE', '2026-11-09', 100.5, bookings: [1]);
        $this->mailing(2, 'SEDEX-ONTEM', '2026-11-08', 30, bookings: [1]);

        Livewire::test(MailCosts::class)
            ->assertSee(['SEDEX-HOJE', 'BKG-1', 'R$ 100,50', 'Total Correios: R$ 100,50'])
            ->assertDontSee('SEDEX-ONTEM');

        $report = app(MailCostsReport::class)(['data_ini' => '2026-11-08', 'data_fim' => '2026-11-09']);
        $this->assertStringStartsWith('%PDF', app(MailCostsPdf::class)->render($report, '2026-11-08', '2026-11-09')->Output('', 'S'));
    }

    /** @param  list<int>  $bookings */
    private function mailing(int $id, string $number, string $date, float $value, array $bookings = [], bool $form = false): void
    {
        DB::table('controle_correio')->insert([
            'controle_correio_id' => $id, 'num_sedex' => $number, 'data_envio' => $date, 'destinatario' => "Destino {$number}",
            'estados_id' => 1, 'valor' => $value, 'formulario' => $form ? 's' : 'n',
        ]);

        foreach ($bookings as $booking) {
            DB::table('ctr_correio_embarque')->insert(['embarque_id' => $booking, 'controle_correio_id' => $id]);
        }
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Charges\Pages\ListCharges;
use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Widgets\BookingsByModal;
use App\Filament\Widgets\BookingsPerMonth;
use App\Filament\Widgets\FinanceStats;
use App\Filament\Widgets\NextArrivals;
use App\Filament\Widgets\NextDepartures;
use App\Filament\Widgets\OperationStats;
use App\Filament\Widgets\OverdueCharges;
use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LegacySchema;

    public function test_widgets_follow_the_booking_and_financial_credentials(): void
    {
        $this->actingAs($this->user('booking'));
        $this->assertTrue(OperationStats::canView());
        $this->assertTrue(NextDepartures::canView());
        $this->assertFalse(FinanceStats::canView());
        $this->assertFalse(OverdueCharges::canView());
        $this->get('/admin')->assertOk()->assertSee('Widgets\\\\NextDepartures', false)->assertSee('Widgets\\\\BookingsPerMonth', false)->assertDontSee('Widgets\\\\OverdueCharges', false);

        $this->actingAs($this->user('financial'));
        $this->assertFalse(OperationStats::canView());
        $this->assertTrue(FinanceStats::canView());
        $this->get('/admin')->assertOk()->assertSee('Widgets\\\\OverdueCharges', false)->assertDontSee('Widgets\\\\NextDepartures', false);
    }

    public function test_dashboard_title_carries_the_year_and_the_dates(): void
    {
        $this->actingAs($this->user('booking'));

        $this->get('/admin')->assertOk()
            ->assertSee('Dashboard 2026')
            ->assertSee('Dados cadastrados desde 01/01/2026 · hoje, '.today()->format('d/m/Y'));
    }

    public function test_widgets_state_the_dates_they_cover(): void
    {
        $this->actingAs($this->user('booking', 'financial'));
        $today = today()->format('d/m/Y');

        Livewire::test(NextDepartures::class)->assertSee("A partir de {$today}");
        Livewire::test(OverdueCharges::class)->assertSee("vencidas antes de {$today}");
        $this->assertStringContainsString('desde 01/01/2026', $this->widgetData(OperationStats::class, 'getDescription'));
        $this->assertStringContainsString("antes de {$today}", $this->widgetData(FinanceStats::class, 'getDescription'));
        $this->assertStringContainsString('a '.today()->format('m/Y'), $this->widgetData(BookingsPerMonth::class, 'getDescription'));
        $this->assertStringContainsString("a {$today}", $this->widgetData(BookingsByModal::class, 'getDescription'));
    }

    public function test_import_only_and_export_only_users_see_only_their_operation(): void
    {
        $this->booking(['booking' => 'IMP', 'impor_expor' => 'i', 'tipo_embarque' => 'm', 'saida_prevista' => today()->addDay()->toDateString()]);
        $this->booking(['booking' => 'EXP', 'impor_expor' => 'e', 'tipo_embarque' => 'a', 'saida_prevista' => today()->addDay()->toDateString()]);

        foreach (['import' => ['Importação', 'Exportação', 'IMP', 'impo'], 'export' => ['Exportação', 'Importação', 'EXP', 'expo']] as $credential => [$seen, $hidden, $booking, $tab]) {
            $this->actingAs($this->user('booking', $credential));

            $stats = collect($this->widgetData(OperationStats::class, 'getStats'))->keyBy(fn ($stat) => $stat->getLabel());
            $this->assertSame('1', $stats['Reservas abertas']->getValue());
            $this->assertStringContainsString(mb_strtolower($seen), $stats['Reservas abertas']->getDescription());
            $this->assertStringNotContainsString(mb_strtolower($hidden), $stats['Reservas abertas']->getDescription());
            $this->assertSame($tab, $this->queryOf($stats['Saídas em 7 dias']->getUrl())['tab']);

            $this->assertSame([$seen], array_column($this->widgetData(BookingsPerMonth::class, 'getData')['datasets'], 'label'));
            $this->assertSame(1, array_sum($this->widgetData(BookingsByModal::class, 'getData')['datasets'][0]['data']));
            Livewire::test(NextDepartures::class)->assertCountTableRecords(1)->assertSee($booking);
            $this->get('/admin')->assertSee('só '.mb_strtolower($seen === 'Importação' ? 'importação' : 'exportação'));
        }

        foreach ([['booking', 'import', 'export'], ['booking'], ['booking', 'admin']] as $credentials) {
            $this->actingAs($this->user(...$credentials));

            $this->assertSame('2', $this->widgetData(OperationStats::class, 'getStats')[0]->getValue());
            $this->assertCount(2, $this->widgetData(BookingsPerMonth::class, 'getData')['datasets']);
            $this->assertArrayNotHasKey('tab', $this->queryOf($this->widgetData(OperationStats::class, 'getStats')[2]->getUrl()));
        }
    }

    public function test_operation_stats_count_open_bookings_and_the_next_seven_days(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['booking' => 'A', 'impor_expor' => 'i', 'saida_prevista' => today()->addDays(2)->toDateString()]);
        $this->booking(['booking' => 'B', 'impor_expor' => 'e', 'saida_prevista' => today()->addDays(30)->toDateString()]);
        $this->booking(['booking' => 'C', 'impor_expor' => 'e', 'finalizado' => 'c']);
        $this->booking(['booking' => 'D', 'impor_expor' => 'i', 'finalizado' => 's', 'chegada_prevista' => today()->addDay()->toDateString()]);

        $stats = collect($this->widgetData(OperationStats::class, 'getStats'))->keyBy(fn ($stat) => $stat->getLabel());

        $this->assertSame('2', $stats['Reservas abertas']->getValue());
        $this->assertSame('1', $stats['Saídas em 7 dias']->getValue());
        $this->assertSame('1', $stats['Chegadas em 7 dias']->getValue());
        $this->assertSame('3', $stats['Cadastradas hoje']->getValue());
    }

    public function test_charts_group_the_month_and_the_modal(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['impor_expor' => 'i']);
        $this->booking(['impor_expor' => 'e']);
        $this->booking(['impor_expor' => 'e', 'tipo_embarque' => 'a']);
        $this->booking(['impor_expor' => 'e', 'finalizado' => 'c']);

        $perMonth = $this->widgetData(BookingsPerMonth::class, 'getData');
        $this->assertSame(min(12, (today()->year - 2026) * 12 + today()->month), count($perMonth['labels']));
        $this->assertSame([1], [array_pop($perMonth['datasets'][0]['data'])]);
        $this->assertSame(2, last($perMonth['datasets'][1]['data']));

        $byModal = $this->widgetData(BookingsByModal::class, 'getData');
        $this->assertSame(['Marítimo', 'Aéreo'], $byModal['labels']);
        $this->assertSame([2, 1], $byModal['datasets'][0]['data']);
    }

    public function test_upcoming_lists_only_open_bookings_from_today_in_date_order(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['booking' => 'LATE', 'saida_prevista' => today()->addDays(5)->toDateString()]);
        $this->booking(['booking' => 'SOON', 'saida_prevista' => today()->addDay()->toDateString()]);
        $this->booking(['booking' => 'PAST', 'saida_prevista' => today()->subDay()->toDateString()]);
        $this->booking(['booking' => 'CANC', 'finalizado' => 'c', 'saida_prevista' => today()->addDay()->toDateString()]);
        $this->booking(['booking' => 'ARR', 'finalizado' => 's', 'chegada_prevista' => today()->addDay()->toDateString()]);

        Livewire::test(NextDepartures::class)->assertCanSeeTableRecords(
            Embarque::whereIn('booking', ['SOON', 'LATE'])->orderBy('saida_prevista')->get(), inOrder: true,
        )->assertCountTableRecords(2);
        Livewire::test(NextArrivals::class)->assertCountTableRecords(1);
    }

    public function test_finance_stats_and_overdue_list_ignore_settled_charges(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge(['total_value' => 1000, 'discount' => 100, 'due_date' => today()->subDays(3)->toDateString()]);
        $this->charge(['total_value' => 500, 'received_value' => 200, 'due_date' => today()->addDays(3)->toDateString()]);
        $this->charge(['total_value' => 800, 'status' => 1, 'received_value' => 800, 'payment_date' => today()->toDateString(), 'due_date' => today()->subDays(9)->toDateString()]);

        $stats = collect($this->widgetData(FinanceStats::class, 'getStats'))->keyBy(fn ($stat) => $stat->getLabel());

        $this->assertSame('R$ 1.200,00', $stats['A receber']->getValue());
        $this->assertSame('R$ 900,00', $stats['Vencidas']->getValue());
        $this->assertSame('R$ 800,00', $stats['Recebido no mês']->getValue());
        Livewire::test(OverdueCharges::class)->assertCountTableRecords(1);
    }

    public function test_overdue_charges_show_the_booking_name_operation_and_type(): void
    {
        $this->actingAs($this->user('financial'));
        $this->booking(['booking' => 'BK-77', 'impor_expor' => 'e', 'tipo_embarque' => 'a']);
        $this->charge(['ref_booking' => DB::table('embarque')->value('embarque_id'), 'total_value' => 400, 'due_date' => today()->subDay()->toDateString()]);

        Livewire::test(OverdueCharges::class)->assertSee('BK-77')->assertSee('Exportação')->assertSee('Aéreo');
    }

    public function test_finance_cards_and_overdue_table_open_lists_with_the_same_records(): void
    {
        $this->actingAs($this->user('financial'));
        $this->charge(['total_value' => 1000, 'due_date' => today()->subDays(3)->toDateString()]);
        $this->charge(['total_value' => 500, 'due_date' => today()->addDays(3)->toDateString()]);
        $this->charge(['total_value' => 300, 'due_date' => '0000-00-00']);
        $this->charge(['total_value' => 200, 'created_at' => '2025-06-01', 'due_date' => '2025-07-01']);
        $this->charge(['total_value' => 800, 'status' => 1, 'received_value' => 800, 'payment_date' => today()->toDateString(), 'due_date' => today()->subDays(9)->toDateString()]);

        $urls = collect($this->widgetData(FinanceStats::class, 'getStats'))->keyBy(fn ($stat) => $stat->getLabel())->map(fn ($stat) => $stat->getUrl());
        $open = fn (string $label) => Livewire::withQueryParams($this->queryOf($urls[$label]))->test(ListCharges::class);

        $open('A receber')->assertCountTableRecords(3);
        $open('Vencidas')->assertCountTableRecords(1);
        $open('Recebido no mês')->assertCountTableRecords(1);

        $table = Livewire::test(OverdueCharges::class);
        $this->assertSame($urls['Vencidas'], $table->instance()->getTable()->getHeaderActions()[0]->getUrl());
    }

    public function test_stat_cards_open_the_bookings_list_with_the_same_bookings(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['booking' => 'IN', 'saida_prevista' => today()->addDays(2)->toDateString()]);
        $this->booking(['booking' => 'FAR', 'saida_prevista' => today()->addDays(30)->toDateString()]);
        $this->booking(['booking' => 'OLD', 'dteCadastro' => '2025-06-01 10:00:00', 'saida_prevista' => today()->addDays(2)->toDateString()]);
        $this->booking(['booking' => 'ARR', 'finalizado' => 's', 'chegada_prevista' => today()->addDays(3)->toDateString()]);

        $urls = collect($this->widgetData(OperationStats::class, 'getStats'))->keyBy(fn ($stat) => $stat->getLabel())->map(fn ($stat) => $stat->getUrl());
        $filters = fn (string $label) => $this->queryOf($urls[$label])['filters'];

        Livewire::withQueryParams(['filters' => $filters('Saídas em 7 dias')])->test(ListEmbarques::class)
            ->assertCanSeeTableRecords(Embarque::where('booking', 'IN')->get())
            ->assertCountTableRecords(1);
        Livewire::withQueryParams(['filters' => $filters('Chegadas em 7 dias')])->test(ListEmbarques::class)
            ->assertCanSeeTableRecords(Embarque::where('booking', 'ARR')->get())
            ->assertCountTableRecords(1);
        Livewire::withQueryParams(['filters' => $filters('Reservas abertas')])->test(ListEmbarques::class)
            ->assertCountTableRecords(2);
    }

    public function test_lists_ignore_the_page_parameter_of_the_url(): void
    {
        $this->actingAs($this->user('booking', 'financial'));
        $this->booking(['saida_prevista' => today()->addDay()->toDateString()]);
        $this->charge(['total_value' => 400, 'due_date' => today()->subDay()->toDateString()]);

        Livewire::withQueryParams(['page' => 45])->test(NextDepartures::class)->assertCountTableRecords(1);
        Livewire::withQueryParams(['page' => 45])->test(OverdueCharges::class)->assertCountTableRecords(1);
    }

    public function test_data_from_before_2026_is_ignored(): void
    {
        $this->actingAs($this->user('booking', 'financial'));
        $this->booking(['dteCadastro' => '2025-12-31 10:00:00', 'saida_prevista' => today()->addDay()->toDateString()]);
        $this->charge(['total_value' => 400, 'created_at' => '2025-12-31', 'due_date' => '2025-12-31']);

        $this->assertSame('0', $this->widgetData(OperationStats::class, 'getStats')[0]->getValue());
        $this->assertSame('R$ 0,00', $this->widgetData(FinanceStats::class, 'getStats')[0]->getValue());
        Livewire::test(NextDepartures::class)->assertCountTableRecords(0);
        Livewire::test(OverdueCharges::class)->assertCountTableRecords(0);
    }

    /** @return array<string, mixed> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /** Os widgets expõem os dados em métodos protected: chama no contexto do próprio widget. */
    private function widgetData(string $widget, string $method): mixed
    {
        return (fn () => $this->{$method}())->call(app($widget));
    }

    private function booking(array $attributes): void
    {
        DB::table('embarque')->insert($attributes + ['finalizado' => 'n', 'tipo_embarque' => 'm', 'impor_expor' => 'i', 'dteCadastro' => now()->toDateTimeString()]);
    }

    private function charge(array $attributes): void
    {
        DB::table('fin_charges')->insert($attributes + ['ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'created_at' => today()->toDateString()]);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

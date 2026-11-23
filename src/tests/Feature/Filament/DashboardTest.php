<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Embarques\Pages\ListEmbarques;
use App\Filament\Widgets\BookingsByModal;
use App\Filament\Widgets\BookingsPerMonth;
use App\Filament\Widgets\NextArrivals;
use App\Filament\Widgets\NextDepartures;
use App\Filament\Widgets\OperationStats;
use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LegacySchema;

    public function test_widgets_follow_the_booking_credential(): void
    {
        $this->actingAs($this->user('booking'));
        $this->assertTrue(OperationStats::canView());
        $this->assertTrue(NextDepartures::canView());
        $this->get('/admin')->assertOk()->assertSee('Widgets\\\\NextDepartures', false)->assertSee('Widgets\\\\BookingsPerMonth', false);

        $this->actingAs($this->user('financial'));
        $this->assertFalse(OperationStats::canView());
        $this->assertFalse(NextDepartures::canView());
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

    public function test_upcoming_lists_ignore_the_page_parameter_of_the_url(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['saida_prevista' => today()->addDay()->toDateString()]);

        Livewire::withQueryParams(['page' => 45])->test(NextDepartures::class)->assertCountTableRecords(1);
    }

    public function test_bookings_from_before_2026_are_ignored(): void
    {
        $this->actingAs($this->user('booking'));
        $this->booking(['dteCadastro' => '2025-12-31 10:00:00', 'saida_prevista' => today()->addDay()->toDateString()]);

        $this->assertSame('0', $this->widgetData(OperationStats::class, 'getStats')[0]->getValue());
        Livewire::test(NextDepartures::class)->assertCountTableRecords(0);
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

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

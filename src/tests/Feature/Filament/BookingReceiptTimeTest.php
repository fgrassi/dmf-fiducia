<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\BookingReceiptTimeReport;
use App\Actions\Financeiro\ExportBookingReceiptTime;
use App\Filament\Livewire\BookingReceiptTimeList;
use App\Filament\Livewire\BookingReceiptTimeSummary;
use App\Filament\Pages\BookingReceiptTime;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class BookingReceiptTimeTest extends TestCase
{
    use LegacySchema;

    private const PERIOD = ['mes_ini' => 3, 'ano_ini' => 2026, 'mes_fim' => 3, 'ano_fim' => 2026];

    public function test_only_director_or_manager_with_financial_open_the_page(): void
    {
        $this->actingAs($this->user('financial'))->get(BookingReceiptTime::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager', 'financial'))->get(BookingReceiptTime::getUrl())->assertOk();
        $this->actingAs($this->user('director'))->get(BookingReceiptTime::getUrl())->assertOk();
    }

    public function test_counts_days_from_creation_to_the_first_payment_inside_the_period(): void
    {
        $this->booking(1, 'A-1', 'i', 7, '2026-03-01', [['2026-03-11', 1], ['2026-03-05', 1], ['2026-03-02', 0]]);
        $this->booking(2, 'A-2', 'e', 7, '2026-03-10', [['2026-03-30', 1]]);
        // Primeira baixa em fevereiro: fora do período, mesmo com outra baixa em março.
        $this->booking(3, 'A-3', 'i', 7, '2026-02-01', [['2026-02-20', 1], ['2026-03-20', 1]]);
        // Tipo `s` e reserva sem baixa não entram.
        $this->booking(4, 'A-4', 'i', 7, '2026-03-01', [['2026-03-09', 1]], 's');
        $this->booking(5, 'A-5', 'i', 7, '2026-03-01', []);

        $report = $this->report();

        $this->assertSame(['A-1', 'A-2'], array_column($report['rows'], 'booking'));
        $this->assertSame([4, 20], array_column($report['rows'], 'dias'));
        $this->assertSame(['count' => 2, 'media' => 12.0], $report['medias']['geral']);
        $this->assertSame(['count' => 1, 'media' => 4.0], $report['medias']['impo']);
        $this->assertSame(['count' => 1, 'media' => 20.0], $report['medias']['expo']);
    }

    public function test_filters_by_type_negotiated_term_and_boleto(): void
    {
        DB::table('fin_clients_boleto')->insert(['ref_client' => 7, 'prazo_imp' => 30, 'prazo_exp' => 0, 'boleto_imp' => 0, 'boleto_exp' => 1]);
        $this->booking(1, 'A-1', 'i', 7, '2026-03-01', [['2026-03-05', 1]]);
        $this->booking(2, 'A-2', 'e', 7, '2026-03-01', [['2026-03-05', 1]]);
        $this->booking(3, 'A-3', 'i', 8, '2026-03-01', [['2026-03-05', 1]]);

        $this->assertSame(['A-1', 'A-2', 'A-3'], array_column($this->report()['rows'], 'booking'));
        $this->assertSame(['A-2'], array_column($this->report(['impor_expor' => 'e'])['rows'], 'booking'));
        $this->assertSame(['A-1'], array_column($this->report(['com_prazo_negociado' => true])['rows'], 'booking'));
        $this->assertSame(['A-2'], array_column($this->report(['com_boleto' => true])['rows'], 'booking'));
        $this->assertSame([], $this->report(['impor_expor' => 'e', 'com_prazo_negociado' => true])['rows']);
    }

    public function test_groups_and_sorts_by_client_and_operation(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 7, 'nome' => 'Beta'], ['pessoa_id' => 8, 'nome' => 'Alfa']]);
        $this->booking(1, 'A-1', 'i', 7, '2026-03-01', [['2026-03-11', 1]]);
        $this->booking(2, 'A-2', 'e', 7, '2026-03-01', [['2026-03-05', 1]]);
        $this->booking(3, 'A-3', 'i', 8, '2026-03-01', [['2026-03-04', 1]]);

        $byClient = $this->report(['agrupar_por' => 'cliente']);
        $this->assertSame(['Alfa', 'Beta'], array_column($byClient['grupos'], 'label'));
        $this->assertSame([3.0, 7.0], array_column($byClient['grupos'], 'media'));

        $byMedia = $this->report(['agrupar_por' => 'cliente_operacao', 'ordenar_por' => 'media', 'ordenar_dir' => 'desc']);
        $this->assertSame(['Beta — IMPO', 'Beta — EXPO', 'Alfa — IMPO'], array_column($byMedia['grupos'], 'label'));

        $this->assertSame(['EXPO', 'IMPO'], array_column($this->report(['agrupar_por' => 'operacao'])['grupos'], 'label'));
    }

    public function test_rejects_an_invalid_period(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('O período início deve ser anterior ou igual ao período fim.');

        $this->report(['mes_ini' => 12]);
    }

    public function test_the_list_and_the_summary_tables_follow_the_filters_and_the_page_warns_about_an_invalid_period(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete']);
        $this->booking(1, 'BKG-1', 'i', 7, now()->startOfMonth()->toDateString(), [[now()->startOfMonth()->addDays(3)->toDateString(), 1]]);

        Livewire::test(BookingReceiptTime::class)
            ->assertSeeLivewire(BookingReceiptTimeList::class)
            ->assertSeeLivewire(BookingReceiptTimeSummary::class)
            ->set('data.mes_ini', 12)
            ->set('data.mes_fim', 1)
            ->call('send')
            ->assertNotified('O período início deve ser anterior ou igual ao período fim.');

        Livewire::test(BookingReceiptTimeList::class, ['reportFilters' => $this->current()])
            ->assertSee(['Reservas', 'Cliente Sete', 'BKG-1', 'IMPO']);

        Livewire::test(BookingReceiptTimeList::class, ['reportFilters' => ['agrupar_por' => 'cliente', 'mostrar_lista' => false] + $this->current()])
            ->assertSee(['Grupos', 'Operações', 'Cliente Sete'])
            ->assertDontSee('BKG-1');

        Livewire::test(BookingReceiptTimeSummary::class, ['reportFilters' => $this->current()])
            ->assertSee(['Resumo geral', 'Geral', 'IMPO', 'EXPO', '3,0']);
    }

    public function test_excel_downloads_and_writes_the_groups_and_the_summary(): void
    {
        $this->actingAs($this->user('director'));
        DB::table('pessoa')->insert(['pessoa_id' => 7, 'nome' => 'Cliente Sete']);
        $this->booking(1, 'BKG-1', 'i', 7, now()->startOfMonth()->toDateString(), [[now()->startOfMonth()->addDays(3)->toDateString(), 1]]);

        Livewire::test(BookingReceiptTime::class)
            ->call('excel')
            ->assertFileDownloaded();

        $sheet = app(ExportBookingReceiptTime::class)->build($this->report(['agrupar_por' => 'cliente'] + $this->current()))->getActiveSheet();
        $this->assertSame('Cliente Sete — 1 ops — média 3 dias', $sheet->getCell('A1')->getValue());
        $this->assertSame('BKG-1', $sheet->getCell('A3')->getValue());
        $this->assertSame(3, $sheet->getCell('J3')->getValue());
    }

    /** @return array<string, int> */
    private function current(): array
    {
        return ['mes_ini' => now()->month, 'ano_ini' => now()->year, 'mes_fim' => now()->month, 'ano_fim' => now()->year];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function report(array $filters = []): array
    {
        return app(BookingReceiptTimeReport::class)($filters + self::PERIOD);
    }

    /** @param  list<array{string, int}>  $charges  [data da baixa, status] */
    private function booking(int $id, string $booking, string $type, int $client, string $created, array $charges, string $mode = 'm'): void
    {
        DB::table('embarque')->insert(['embarque_id' => $id, 'booking' => $booking, 'impor_expor' => $type, 'cliente_id' => $client, 'data_topo' => $created, 'tipo_embarque' => $mode]);

        foreach ($charges as [$paid, $status]) {
            DB::table('fin_charges')->insert(['ref_type' => 1, 'ref_booking' => $id, 'status' => $status, 'created_at' => $created, 'payment_date' => $paid]);
        }
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

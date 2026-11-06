<?php

namespace Tests\Feature\Filament;

use App\Actions\Financeiro\ExportInvoiceCoverFileReport;
use App\Actions\Financeiro\InvoiceCoverFileReport;
use App\Filament\Pages\InvoiceCoverFile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class InvoiceCoverFileTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('embarque')->insert(['embarque_id' => 1, 'booking' => 'BKG-1', 'cliente_id' => 7, 'tipo_embarque' => 'm']);
        DB::table('capa_processo_arquivo')->insert([
            ['capa_processo_arquivo_id' => 1, 'nome' => 'Março 2026', 'mes' => 3, 'ano' => 2026],
            ['capa_processo_arquivo_id' => 2, 'nome' => 'Fevereiro 2026', 'mes' => 2, 'ano' => 2026],
            ['capa_processo_arquivo_id' => 3, 'nome' => 'Maio 2026', 'mes' => 5, 'ano' => 2026],
            ['capa_processo_arquivo_id' => 4, 'nome' => 'Março 2026 (extra)', 'mes' => 3, 'ano' => 2026],
        ]);
    }

    public function test_only_the_financial_credential_opens_the_page(): void
    {
        $this->actingAs($this->user('import'))->get(InvoiceCoverFile::getUrl())->assertForbidden();
        $this->actingAs($this->user('financial'))->get(InvoiceCoverFile::getUrl())->assertOk();
    }

    public function test_requires_a_valid_period_and_an_existing_file(): void
    {
        foreach ([
            [['data_ini' => null], 'Informe a data inicial e a data final da emissão.'],
            [['data_ini' => '2026-04-01', 'data_fim' => '2026-03-01'], 'A data inicial deve ser anterior ou igual à data final.'],
            [['capa_processo_arquivo_id' => null], 'Selecione o arquivo de capa.'],
            [['capa_processo_arquivo_id' => 99], 'Arquivo de capa não encontrado.'],
        ] as [$filters, $message]) {
            try {
                $this->report($filters);
                $this->fail("Sem erro para: {$message}");
            } catch (DomainException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_invoices_are_split_by_the_month_of_their_cover_file(): void
    {
        $key = str_repeat('1', 23).'0000000000123'.str_repeat('2', 14);

        $both = $this->invoice(['chave_acesso' => $key]);
        $this->cover($both, archive: 1);
        $this->cover($both, archive: 2, kind: 'receita');

        $later = $this->invoice(['created_at' => '2026-03-11 10:00:00'], numero: 'NF-LATER');
        $expense = $this->cover($later, archive: 3, value: 1000);
        $sameCover = $this->invoice(['created_at' => '2026-03-12 10:00:00'], numero: 'NF-SAME');
        DB::table('fin_charges_items')->insert(['ref_charge' => $sameCover, 'ref_expense' => $expense, 'ref_currency' => 1, 'created_at' => '2026-03-01']);

        $this->invoice([], numero: 'NF-NONE');
        $this->cover($this->invoice([], numero: 'NF-SAMEMONTH'), archive: 4);
        $this->cover($this->invoice([], numero: 'NF-NOFILE'), archive: null);

        $this->invoice(['payload_json' => json_encode(['data_emissao' => '2026-04-02'])], numero: 'NF-OUT');
        $this->invoice(['status' => 'cancelada'], numero: 'NF-CANCELED');

        $this->paidByCarrier($expense, 300);
        $this->paidByCarrier($expense, 200, disabled: true);
        $this->paidByOthers($expense, 100);

        $report = $this->report();

        $this->assertSame('01/03/2026 a 31/03/2026', $report['periodo']);
        $this->assertSame('Março 2026', $report['arquivo_nome']);
        $this->assertSame(['no_arquivo' => 1, 'anterior' => 1, 'posterior' => 2, 'nenhum' => 2, 'mesmo_mes' => 1, 'custo_em_aberto' => 600.0], $report['resumo']);
        $this->assertSame([['123', 'Fevereiro 2026']], array_map(fn ($r) => [$r['numero_nf'], $r['arquivo_nome']], $report['anterior']));
        $this->assertSame([['NF-LATER', 600.0], ['NF-SAME', 600.0]], array_map(fn ($r) => [$r['numero_nf'], $r['custo_em_aberto']], $report['posterior']));
        $this->assertSame(['NF-NOFILE', 'NF-NONE'], array_column($report['nenhum'], 'numero_nf'));
        $this->assertSame(['NF-SAMEMONTH'], array_column($report['mesmo_mes'], 'numero_nf'));
    }

    public function test_page_shows_the_lists_and_downloads_the_excel(): void
    {
        $this->actingAs($this->user('financial'));
        $this->cover($this->invoice([], numero: 'NF-LATER'), archive: 3, value: 250);

        Livewire::test(InvoiceCoverFile::class)
            ->call('send')
            ->assertHasFormErrors(['data_ini' => 'required', 'data_fim' => 'required', 'capa_processo_arquivo_id' => 'required'])
            ->fillForm(['data_ini' => '2026-03-01', 'data_fim' => '2026-03-31', 'capa_processo_arquivo_id' => 1])
            ->call('send')
            ->assertSee(['NFS-e com capa em arquivo posterior', 'NF-LATER', 'Maio 2026', '250,00', 'BKG-1'])
            ->assertDontSee('NFS-e em outro arquivo do mesmo mês')
            ->call('excel')
            ->assertFileDownloaded();

        $book = app(ExportInvoiceCoverFileReport::class)->build($this->report());

        $this->assertSame(['Resumo', 'NF capa anterior', 'NF capa posterior', 'NF sem arquivo de capa'], $book->getSheetNames());
        $this->assertSame('NF-LATER', $book->getSheetByName('NF capa posterior')->getCell('B2')->getValue());
        $this->assertEquals(250, $book->getSheetByName('NF capa posterior')->getCell('F3')->getValue());
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function report(array $filters = []): array
    {
        return app(InvoiceCoverFileReport::class)($filters + ['data_ini' => '2026-03-01', 'data_fim' => '2026-03-31', 'capa_processo_arquivo_id' => 1]);
    }

    /** NFS-e autorizada de uma cobrança nova; devolve a cobrança. @param  array<string, mixed>  $nfse */
    private function invoice(array $nfse, string $numero = 'NF-1'): int
    {
        $charge = DB::table('fin_charges')->insertGetId(['ref_type' => 1, 'ref_booking' => 1, 'status' => 0, 'created_at' => '2026-03-05 08:00:00', 'num_nota_fiscal' => $numero]);
        DB::table('fin_charges_nfse')->insert([
            'ref_charge' => $charge, 'id_externo' => "ext-{$charge}", 'status' => 'autorizada', 'valor_servicos' => 500,
            'created_at' => '2026-03-10 10:00:00', ...$nfse,
        ]);

        return $charge;
    }

    /** Capa com um item (de despesa ou de receita) ligado à cobrança; devolve a despesa. */
    private function cover(int $charge, ?int $archive, string $kind = 'despesa', float $value = 100): int
    {
        $expense = DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => 1]);
        $cover = DB::table('capa_processo')->insertGetId(['embarque_id' => 1, 'moeda_id' => 1, 'capa_processo_arquivo_id' => $archive]);
        DB::table('fin_charges_items')->insert(['ref_charge' => $charge, 'ref_expense' => $expense, 'ref_currency' => 1, 'created_at' => '2026-03-01']);
        DB::table("capa_processo_{$kind}_item")->insert(['capa_processo_id' => $cover, 'fin_expenses_values_id' => $expense, 'nome' => 'Frete', 'valor' => $value]);

        return $expense;
    }

    private function paidByCarrier(int $expense, float $value, bool $disabled = false): void
    {
        $order = DB::table('fin_payment_orders')->insertGetId(['ref_armador' => 1, 'created_at' => '2026-03-01', 'status' => 1, 'ref_type' => 1, 'ref_embarque' => 1]);
        DB::table('fin_payment_order_items')->insert(['ref_payment_order' => $order, 'ref_expense' => $expense, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => '2026-03-01', 'ref_bl' => 1]);

        if ($disabled) {
            DB::table('fin_payment_order_items_disabled')->insert(['ref_fin_payment_order' => $order, 'ref_expense' => $expense, 'created_by' => 1, 'created_at' => '2026-03-02', 'status' => 0]);
        }
    }

    private function paidByOthers(int $expense, float $value): void
    {
        $order = DB::table('ordem_pagamento')->insertGetId(['ref_embarque' => 1, 'created_at' => '2026-03-01', 'status' => 1, 'ref_type' => 1]);
        DB::table('ordem_pagamento_item')->insert(['ordem_pagamento_id' => $order, 'ref_expense' => $expense, 'ref_currency' => 1, 'item_value' => $value, 'created_at' => '2026-03-01']);
    }

    private function user(string ...$credentials): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => implode(',', $credentials)]));
    }
}

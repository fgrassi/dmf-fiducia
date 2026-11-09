<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\CompanyRatesPdf;
use App\Filament\Resources\CompanyRates\Pages\ManageCompanyRates;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCharges;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CompanyRatesPdfTest extends TestCase
{
    use BuildsCharges;
    use LegacySchema;

    public function test_lists_negotiated_rates_by_client_name(): void
    {
        DB::table('pessoa')->insert([['pessoa_id' => 1, 'nome' => 'Zeta Ltda'], ['pessoa_id' => 2, 'nome' => 'Alfa SA']]);
        DB::table('fin_currencies_rates')->insert(['id' => 1, 'name' => 'USD', 'ref_currency' => 2]);
        DB::table('fin_companies_rates')->insert([
            ['ref_pessoa' => 1, 'ref_currency_rate' => 1, 'tax' => 1.5],
            ['ref_pessoa' => 2, 'ref_currency_rate' => 1, 'tax' => 2],
        ]);

        $action = app(CompanyRatesPdf::class);

        $this->assertSame(['Alfa SA', 'Zeta Ltda'], $action->rows()->pluck('pessoa.nome')->all());
        $this->assertStringStartsWith('%PDF', $action->render($action->rows())->Output('', 'S'));
    }

    public function test_print_action_downloads_the_pdf(): void
    {
        $this->actingAs($this->user('financial'));

        Livewire::test(ManageCompanyRates::class)
            ->callAction('print')
            ->assertFileDownloaded('taxas-negociadas.pdf');
    }
}

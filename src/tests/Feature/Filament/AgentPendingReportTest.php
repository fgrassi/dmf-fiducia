<?php

namespace Tests\Feature\Filament;

use App\Actions\AgentNotes\AgentPendingStatement;
use App\Actions\AgentNotes\AgentPendingSummary;
use App\Filament\Pages\AgentPendingReport;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AgentPendingReportTest extends TestCase
{
    use LegacySchema;

    private function agent(int $id, string $name, int $restriction = 0): void
    {
        DB::table('pessoa')->insert(['pessoa_id' => $id, 'nome' => $name]);
        DB::table('pessoa_tipo_pessoa')->insert(['pessoa_id' => $id, 'tipo_pessoa_id' => 1]);
        DB::table('agente_pais')->insert(['agente_id' => $id, 'pais_id' => 1, 'created_by' => 1, 'restricao' => $restriction]);
    }

    private function shipment(string $mode = 'i', string $type = 'm', int $id = 10): void
    {
        DB::table('pessoa')->insertOrIgnore(['pessoa_id' => 7, 'nome' => 'Cliente Sete']);
        DB::table('cmn_currencies')->insertOrIgnore(['id' => 2, 'name' => 'Dolar', 'symbol' => 'US$']);
        DB::table('embarque')->updateOrInsert(['embarque_id' => $id], ['booking' => "BKG{$id}", 'house_bl' => "HBL{$id}", 'impor_expor' => $mode, 'tipo_embarque' => $type, 'pessoa_id_cliente' => 7]);
    }

    private function debit(int $agent, float $value, array $attributes = []): void
    {
        DB::table('fin_debit_notes')->insert($attributes + [
            'ref_embarque' => 10, 'ref_agente' => $agent, 'name' => 'DN', 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1,
            'moeda_id' => 2, 'due_to_dmf' => $value, 'status_pagamento' => 0,
        ]);
    }

    private function credit(int $agent, float $dmfAgent, float $dmf = 0, array $attributes = []): void
    {
        DB::table('fin_credit_notes')->insert($attributes + [
            'ref_embarque' => 10, 'ref_agente' => $agent, 'name' => 'CN', 'created_at' => '2026-10-10 10:00:00', 'created_by' => 1,
            'moeda_id' => 2, 'due_to_dmf_agent' => $dmfAgent, 'due_to_dmf' => $dmf, 'status_pagamento' => 0,
        ]);
    }

    private function varied(int $agent, int $noteType, string $mode, float $value, string $shipper = 'ACME'): void
    {
        DB::table('fin_despesas_variadas')->insert([
            'agente_id' => $agent, 'tipo_nota' => $noteType, 'impor_expor' => $mode, 'valor' => $value, 'shipper' => $shipper,
            'cmn_currencies_id' => 2, 'status_pagamento' => 0, 'data_despesa' => '2026-10-11', 'invoice' => 'INV', 'booking' => 'B', 'hbl' => 'H',
        ]);
    }

    private function user(string $credentials = 'financial'): User
    {
        return User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana'.uniqid(), 'senha' => 'x', 'status' => 1, 'credentials' => $credentials]));
    }

    public function test_only_manager_and_financial_open_the_page(): void
    {
        $this->actingAs($this->user('booking'))->get(AgentPendingReport::getUrl())->assertForbidden();
        $this->actingAs($this->user('manager'))->get(AgentPendingReport::getUrl())->assertOk();
        $this->actingAs($this->user('financial'))->get(AgentPendingReport::getUrl())->assertOk();
    }

    public function test_splits_open_notes_between_due_to_agent_and_due_to_dmf(): void
    {
        $this->agent(5, 'Agente Um');
        $this->shipment('i', 'm', 10);
        $this->shipment('e', 'm', 11);
        $this->debit(5, 100);
        $this->debit(5, 40, ['status_pagamento' => 1]);
        $this->credit(5, 7, 3);
        $this->debit(5, 20, ['ref_embarque' => 11]);
        $this->credit(5, 9, 4, ['ref_embarque' => 11]);
        $this->varied(5, 1, 'e', 5);
        $this->varied(5, 2, 'i', 6);
        $this->varied(5, 2, 'i', 2, 'COURRIER');

        $row = app(AgentPendingSummary::class)(false)->firstWhere('pessoa_id', 5);

        // devido ao agente: débito de impo 100, crédito de expo 9, variadas crédito expo 5 e débito impo 6
        $this->assertEquals(100 + 9 + 5 + 6, $row['due_to_agente']);
        // devido à DMF: crédito de impo 3+7, débito de expo 20 e variada courier de impo 2
        $this->assertEquals(10 + 20 + 2, $row['due_to_dmf']);
        $this->assertEquals(120 - 32, $row['total']);
    }

    public function test_hides_agents_without_pending_and_with_restriction_unless_all_is_asked(): void
    {
        $this->agent(5, 'Com pendência');
        $this->agent(6, 'Sem pendência');
        $this->agent(8, 'Com restrição', 1);
        $this->shipment('i');
        $this->debit(5, 10);
        $this->debit(8, 10);

        $this->assertSame([5], app(AgentPendingSummary::class)(false)->pluck('pessoa_id')->all());
        $this->assertSame([8], app(AgentPendingSummary::class)(true)->pluck('pessoa_id')->all());
    }

    public function test_aic_asia_is_added_to_the_aic_row_but_not_to_its_total(): void
    {
        $this->agent(1838, 'AIC');
        $this->agent(3548, 'AIC Asia');
        $this->shipment('i');
        $this->debit(1838, 10);
        $this->debit(3548, 5);

        $rows = app(AgentPendingSummary::class)(false);

        $this->assertSame([1838], $rows->pluck('pessoa_id')->all());
        $this->assertEquals(15, $rows[0]['due_to_agente']);
        $this->assertEquals(10, $rows[0]['total']);
    }

    public function test_the_page_lists_the_agent_and_edits_the_exchange_deadline(): void
    {
        $this->actingAs($this->user());
        $this->agent(5, 'Agente Um');
        $this->shipment('i');
        $this->debit(5, 10);

        Livewire::test(AgentPendingReport::class)
            ->assertSee(['Agente Um', 'USD 10,00'])
            ->callAction(TestAction::make('limit')->table('5'), ['data_limite_cambio' => '2026-12-01']);

        $this->assertSame('2026-12-01', substr((string) DB::table('pessoa')->where('pessoa_id', 5)->value('data_limite_cambio'), 0, 10));
    }

    public function test_history_adds_and_removes_observations(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->agent(5, 'Agente Um');
        $this->shipment('i');
        $this->debit(5, 10);
        $keep = DB::table('agente_observacao')->insertGetId(['agente_id' => 5, 'observacao' => 'ficou', 'data_observacao' => '2026-10-01', 'created_at' => now(), 'created_by' => 1]);
        DB::table('agente_observacao')->insert(['agente_id' => 5, 'observacao' => 'saiu', 'data_observacao' => '2026-10-02', 'created_at' => now(), 'created_by' => 1]);

        Livewire::test(AgentPendingReport::class)
            ->callAction(TestAction::make('history')->table('5'), ['items' => [
                ['id' => $keep, 'data_observacao' => '2026-10-01', 'observacao' => 'ficou'],
                ['id' => null, 'data_observacao' => '2026-11-05', 'observacao' => 'nova'],
            ]]);

        $this->assertSame(['ficou', 'nova'], DB::table('agente_observacao')->where('agente_id', 5)->orderBy('agente_observacao_id')->pluck('observacao')->all());
        $this->assertSame($user->getKey(), (int) DB::table('agente_observacao')->where('observacao', 'nova')->value('created_by'));
    }

    public function test_the_statement_lists_the_notes_of_each_side_and_the_balance(): void
    {
        $this->agent(5, 'Agente Um');
        $this->shipment('e');
        $this->credit(5, 30);
        $this->debit(5, 10);

        $book = IOFactory::load($this->writeTemp(app(AgentPendingStatement::class)(5, 't')));
        $cells = collect($book->getActiveSheet()->toArray(null, true, false))->flatten()->filter()->values();

        $this->assertContains('Agent: Agente Um', $cells);
        $this->assertContains('TOTAL DUE TO AGENTE UM', $cells);
        foreach ([-30, 10, 20] as $value) {
            $this->assertTrue($cells->contains(fn ($cell) => is_numeric($cell) && (float) $cell === (float) $value), "valor {$value} ausente");
        }
    }

    public function test_aic_agents_get_one_statement_per_shipment_type(): void
    {
        $this->agent(10960, 'AIC Air');
        $this->shipment('e', 'a');
        $this->credit(10960, 30);

        $air = collect(IOFactory::load($this->writeTemp(app(AgentPendingStatement::class)(10960, 'a')))->getActiveSheet()->toArray())->flatten();
        $sea = collect(IOFactory::load($this->writeTemp(app(AgentPendingStatement::class)(10960, 'm')))->getActiveSheet()->toArray())->flatten();

        $this->assertTrue($air->contains('CN'));
        $this->assertFalse($sea->contains('CN'));
    }

    public function test_sending_saves_the_contacts_and_emails_finance_and_the_agent(): void
    {
        $this->actingAs($this->user());
        $this->agent(5, 'Agente Um');
        $this->shipment('e');
        $this->credit(5, 30);

        Livewire::test(AgentPendingReport::class)
            ->callAction(TestAction::make('email_t')->table('5'), ['contatos' => 'a@x.com, b@x.com'])
            ->assertHasNoFormErrors();

        $this->assertSame('a@x.com, b@x.com', DB::table('pessoa')->where('pessoa_id', 5)->value('contato_agente_financeiro'));
        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringStartsWith('[DMF Logistics] Statement of Account: Agente Um', $message->getSubject());
        $this->assertEqualsCanonicalizing(['financeiro@dmflog.com.br', 'a@x.com', 'b@x.com'], collect($message->getTo())->map->getAddress()->all());
        $this->assertCount(1, $message->getAttachments());
    }

    public function test_sending_rejects_an_invalid_email(): void
    {
        $this->actingAs($this->user());
        $this->agent(5, 'Agente Um');
        $this->shipment('e');
        $this->credit(5, 30);

        Livewire::test(AgentPendingReport::class)
            ->callAction(TestAction::make('email_t')->table('5'), ['contatos' => 'nao-e-email'])
            ->assertHasFormErrors(['contatos']);

        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    private function writeTemp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xls');
        file_put_contents($path, $content);

        return $path;
    }
}

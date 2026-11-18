<?php

namespace Tests\Feature\Actions\Financeiro;

use App\Actions\Financeiro\ExchangeDeadlineNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class ExchangeDeadlineNoticeTest extends TestCase
{
    use LegacySchema;

    public function test_lists_agents_whose_exchange_deadline_falls_in_the_next_five_days(): void
    {
        $this->travelTo('2026-11-09');
        DB::table('pessoa')->insert([
            ['pessoa_id' => 1, 'nome' => 'Agente Hoje', 'data_limite_cambio' => '2026-11-09'],
            ['pessoa_id' => 2, 'nome' => 'Agente Dia 5', 'data_limite_cambio' => '2026-11-14'],
            ['pessoa_id' => 3, 'nome' => 'Agente Dia 6', 'data_limite_cambio' => '2026-11-15'],
            ['pessoa_id' => 4, 'nome' => 'Agente Ontem', 'data_limite_cambio' => '2026-11-08'],
            ['pessoa_id' => 5, 'nome' => 'Sem Prazo', 'data_limite_cambio' => null],
        ]);

        $sent = app(ExchangeDeadlineNotice::class)();

        $this->assertSame(2, $sent);
        $this->assertSame(['[Aviso] Data de Limite para Envio de Câmbios'], $this->sentSubjects()->all());
        $this->assertSame(['financeiro@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString('<td>Agente Hoje</td><td>09/11/2026</td></tr><tr><td>Agente Dia 5</td><td>14/11/2026</td>', $this->sentHtml());
        foreach (['Dia 6', 'Ontem', 'Sem Prazo'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $this->sentHtml());
        }
    }

    public function test_sends_nothing_when_no_deadline_is_close(): void
    {
        $this->travelTo('2026-11-09');

        $this->assertSame(0, app(ExchangeDeadlineNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }
}

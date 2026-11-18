<?php

namespace Tests\Feature\Actions\Comissoes;

use App\Actions\Comissoes\OpenCommissionsNotice;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class OpenCommissionsNoticeTest extends TestCase
{
    use LegacySchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09');
        DB::table('comissao_embarque_responsavel')->insert([
            ['comissao_embarque_responsavel_id' => 1, 'nome' => 'Beto'],
            ['comissao_embarque_responsavel_id' => 2, 'nome' => 'Ana'],
        ]);
        DB::table('cmn_currencies')->insert(['id' => 1, 'name' => 'Dólar', 'symbol' => 'US$']);
        DB::table('embarque')->insert([['embarque_id' => 10, 'booking' => 'BKG10'], ['embarque_id' => 11, 'booking' => 'BKG11']]);
    }

    public function test_lists_open_commissions_of_2026_by_responsible_and_emails_finance(): void
    {
        $this->commission(1, 'Beto', 10, status: null, type: 1, valor: 1500.5);
        $this->commission(2, 'Ana', 11, status: 0, commissionType: 1, valor: 20);
        $this->commission(3, 'Ana', 11, status: 1);
        $this->commission(4, 'Ana', 11, status: 0, createdAt: '2025-12-31 23:59:59');
        $this->containers(11, 2);

        $sent = app(OpenCommissionsNotice::class)();

        $this->assertSame(2, $sent);
        $this->assertSame(['Comissões em aberto - 09/11/2026'], $this->sentSubjects()->all());
        $this->assertSame(['financeiro5@dmflog.com.br', 'marcia@dmflog.com.br'], $this->sentRecipients());
        $this->assertStringContainsString(
            '<td>Ana</td><td>BKG11</td><td>Container</td><td>2</td><td>Pagar</td><td>Dólar (US$)</td><td align=\'right\'>20,00</td>'
            .'</tr><tr><td>Beto</td><td>BKG10</td><td>Processo</td><td>0</td><td>Receber</td><td>Dólar (US$)</td><td align=\'right\'>1.500,50</td>',
            $this->sentHtml(),
        );
    }

    public function test_sends_nothing_when_no_commission_is_open(): void
    {
        $this->commission(1, 'Ana', 10, status: 1);

        $this->assertSame(0, app(OpenCommissionsNotice::class)());
        $this->assertSame([], $this->sentSubjects()->all());
    }

    private function commission(int $id, string $responsible, int $bookingId, ?int $status, int $type = 0, int $commissionType = 0, float $valor = 10, string $createdAt = '2026-03-10 10:00:00'): void
    {
        DB::table('comissao_embarque')->insert([
            'comissao_embarque_id' => $id,
            'comissao_embarque_responsavel_id' => $responsible === 'Ana' ? 2 : 1,
            'embarque_id' => $bookingId,
            'tipo_comissao' => $commissionType,
            'tipo' => $type,
            'moeda_id' => 1,
            'valor_unitario' => $valor,
            'created_at' => $createdAt,
            'created_by' => 1,
            'status' => $status,
        ]);
    }

    private function containers(int $bookingId, int $count): void
    {
        DB::table('bl')->insert(['bl_id' => $bookingId, 'embarque_id' => $bookingId]);

        for ($i = 0; $i < $count; $i++) {
            DB::table('container')->insert(['bl_id' => $bookingId]);
        }
    }
}

<?php

namespace Tests\Feature\Actions\Carriers;

use App\Actions\Carriers\AddMatrixExpenses;
use App\Models\CmnArmadorPorto;
use App\Models\FinExpensesMatrix;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class AddMatrixExpensesTest extends TestCase
{
    use LegacySchema;

    private CmnArmadorPorto $port;

    private int $expense;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $carrier = DB::table('armador')->insertGetId(['nome' => 'Hapag']);
        $santos = DB::table('porto')->insertGetId(['nome' => 'Santos']);
        $this->port = CmnArmadorPorto::create(['ref_armador' => $carrier, 'ref_porto' => $santos]);
        $this->expense = DB::table('fin_expenses')->insertGetId(['name' => 'THC']);
        $this->user = User::find(DB::table('usuario')->insertGetId(['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'auxiliares']));
    }

    public function test_creates_one_row_per_equipment_or_a_single_row_without_equipment(): void
    {
        $this->add(['ref_equipamento' => [10, 11]]);
        $this->add(['ref_equipamento' => []]);

        $this->assertEquals([10, 11, null], FinExpensesMatrix::orderBy('id')->pluck('ref_equipamento')->all());
        $this->assertEquals([0.0], FinExpensesMatrix::pluck('imo_value')->unique()->map(fn ($v) => (float) $v)->all());
        $this->assertSame($this->user->getKey(), (int) FinExpensesMatrix::first()->created_by);
    }

    public function test_new_value_closes_the_current_one_and_warns_open_shipments(): void
    {
        $old = $this->add(['due_from' => '2026-01-01'])[0];
        $this->openShipment('i', '2026-12-10');

        $this->add(['due_from' => '2026-11-01', 'expense_value' => 120]);

        $this->assertSame('2026-11-01', $old->fresh()->due_to);
        $this->assertSame(['Atualizacão de taxas [IMPO]'], $this->sentSubjects()->all());
        $this->assertStringContainsString('BKG1 (THC) - Cliente SA', $this->lastMail()->getHtmlBody());
        $this->assertEqualsCanonicalizing(['importacao@dmflog.com.br', 'financeiro@dmflog.com.br', 'chamado@dmflog.com.br'], array_map(fn ($a) => $a->getAddress(), $this->lastMail()->getTo()));
    }

    public function test_new_expense_warns_shipments_in_the_carrier_port_and_user(): void
    {
        $this->openShipment('i', '2026-12-10');
        $this->openShipment('e', '2026-12-10', 'BKG-EXPO');

        $this->add(['due_from' => '2026-11-01']);

        $this->assertSame(['Atualizacão de taxas [IMPO]: Nova despesa Armador Hapag'], $this->sentSubjects()->all());
        $body = $this->lastMail()->getHtmlBody();
        $this->assertStringContainsString('pelo usuario Ana', $body);
        $this->assertStringContainsString('BKG1', $body);
        $this->assertStringNotContainsString('BKG-EXPO', $body);
    }

    public function test_no_mail_without_open_shipments(): void
    {
        $this->openShipment('i', '2026-12-10', finalized: 'c');
        $this->openShipment('i', '2026-10-01');

        $this->add(['due_from' => '2026-11-01']);

        $this->assertSame([], $this->sentSubjects()->all());
    }

    /** @return list<FinExpensesMatrix> */
    private function add(array $data): array
    {
        return app(AddMatrixExpenses::class)($this->port, $data + [
            'ref_expense' => $this->expense,
            'ref_currency' => 1,
            'type' => 'i',
            'billing' => 'p',
            'expense_value' => 100,
            'imo_value' => null,
            'compra_minimo' => null,
            'venda_minimo' => null,
            'pais_origem_id' => null,
            'due_from' => '2026-01-01',
            'due_to' => null,
            'ref_equipamento' => [],
        ], $this->user);
    }

    private function openShipment(string $operation, string $date, string $booking = 'BKG1', string $finalized = 'n'): void
    {
        $client = DB::table('pessoa')->insertGetId(['nome' => 'Cliente SA']);
        $id = DB::table('embarque')->insertGetId([
            'armador_id' => $this->port->ref_armador,
            'porto_destino_id' => $this->port->ref_porto,
            'porto_origem_id' => $this->port->ref_porto,
            'chegada_prevista' => $date,
            'saida_prevista' => $date,
            'impor_expor' => $operation,
            'tipo_embarque' => 'm',
            'finalizado' => $finalized,
            'booking' => $booking,
            'pessoa_id_cliente' => $client,
        ]);
        DB::table('mercadoria')->insert(['embarque_id' => $id, 'tipo_container_id' => 10]);
        DB::table('fin_expenses_values')->insert(['ref_embarque' => $id, 'ref_expense' => $this->expense]);
    }

    private function lastMail()
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $m) => $m->getOriginalMessage())
            ->last();
    }
}

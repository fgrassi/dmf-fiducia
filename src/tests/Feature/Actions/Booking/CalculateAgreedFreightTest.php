<?php

namespace Tests\Feature\Actions\Booking;

use App\Actions\Booking\CalculateAgreedFreight;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CalculateAgreedFreightTest extends TestCase
{
    use LegacySchema;

    private const USD = 2;

    private const BL_UNIT = 1;

    private const CONTAINER_UNIT = 2;

    private const WEIGHT_MEASURE_UNIT = 3;

    private const UNIT_WITHOUT_RULE = 4;

    private const LCL = 20;

    public function test_lcl_uses_the_greater_of_sale_times_factor_and_the_minimum(): void
    {
        $booking = $this->importBooking(['peso_cotacao' => 2, 'volume_cotacao' => 3.5]);
        $this->mercadoria($booking, self::LCL);
        $ids = [
            $this->expense($booking, 200, 'Ocean Freight', self::WEIGHT_MEASURE_UNIT, ['selling_rate' => 100, 'sr_min' => 200]),
            $this->expense($booking, 43, 'Capatazia', self::WEIGHT_MEASURE_UNIT, ['selling_rate' => 10, 'sr_min' => 90]),
            $this->expense($booking, 60, 'Documentação', self::BL_UNIT, ['selling_rate' => 1234.5]),
            $this->expense($booking, 61, 'Sem regra', self::UNIT_WITHOUT_RULE, ['selling_rate' => 5]),
        ];

        $value = app(CalculateAgreedFreight::class)($booking, $ids, null, 7);

        $this->assertSame("\nOcean Freight USD 350,00\nCapatazia USD 90,00\nDocumentação USD 1.234,50", $value);
        $this->assertSame($value, DB::table('valor_frete_embarque')->value('valor'));
    }

    public function test_fcl_uses_the_sale_and_names_the_equipment_when_charged_per_container(): void
    {
        $booking = $this->importBooking();
        DB::table('tipo_container')->insert(['tipo_container_id' => 5, 'nome' => "20' DRY"]);
        $ids = [
            $this->expense($booking, 1, 'Ocean Freight', self::CONTAINER_UNIT, ['selling_rate' => 1500, 'ref_equipment' => 5]),
            $this->expense($booking, 62, 'THC', self::BL_UNIT, ['selling_rate' => 80]),
        ];

        $value = app(CalculateAgreedFreight::class)($booking, $ids, null, 7);

        $this->assertSame("\nOcean Freight / 20' DRY USD 1.500,00\nTHC / BL USD 80,00", $value);
    }

    public function test_air_multiplies_only_the_weight_measure_expenses_by_the_rounded_factor(): void
    {
        $booking = $this->importBooking(['tipo_embarque' => 'a', 'peso_cotacao' => 1234.567, 'volume_cotacao' => 1]);
        $ids = [
            $this->expense($booking, 73, 'Air Freight', self::WEIGHT_MEASURE_UNIT, ['selling_rate' => 2]),
            $this->expense($booking, 62, 'Handling', self::BL_UNIT, ['selling_rate' => 80]),
        ];

        $value = app(CalculateAgreedFreight::class)($booking, $ids, null, 7);

        $this->assertSame("\nAir Freight USD 2.469,14", $value);
    }

    /** @param  array<string, mixed>  $booking */
    #[DataProvider('missingRequiredExpenses')]
    public function test_blocks_when_the_required_expenses_are_not_marked(array $booking, bool $lcl, array $added, array $marked, string $message): void
    {
        $booking = $this->importBooking($booking);
        $lcl && $this->mercadoria($booking, self::LCL);
        $expenses = collect($added)->mapWithKeys(fn (int $ref) => [$ref => $this->expense($booking, $ref, "D{$ref}", self::BL_UNIT, ['selling_rate' => 1])]);

        try {
            app(CalculateAgreedFreight::class)($booking, $expenses->only($marked)->values()->all(), null, 7);
            $this->fail('O cálculo deveria ser bloqueado.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
            $this->assertSame(0, DB::table('valor_frete_embarque')->count());
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool, 2: list<int>, 3: list<int>, 4: string}> */
    public static function missingRequiredExpenses(): array
    {
        return [
            'LCL sem capatazia ou THC' => [[], true, [200, 291], [200, 291], 'É necessário incluir as despesas obrigatórias! (Ocean Freigth e Capatazia ou THC)'],
            'LCL sem frete' => [[], true, [43], [43], 'É necessário incluir as despesas obrigatórias! (Ocean Freigth e Capatazia ou THC)'],
            'LCL com EBS na reserva e fora da seleção' => [[], true, [200, 43, 291], [200, 43], 'É necessário incluir as despesas obrigatórias! (Ocean Freigth, EBS e Capatazia ou THC)'],
            'FCL sem frete' => [[], false, [62], [62], 'É necessário incluir as despesas obrigatórias! (Ocean Freigth)'],
            'aéreo sem frete aéreo' => [['tipo_embarque' => 'a', 'peso_cotacao' => 10], false, [62], [62], 'É necessário incluir as despesas obrigatórias! (Air Freigth - W/M)'],
            'aéreo sem fator' => [['tipo_embarque' => 'a'], false, [73], [73], 'É necessário que o fator seja maior que 0'],
        ];
    }

    public function test_lcl_accepts_thc_in_place_of_capatazia_and_a_marked_ebs(): void
    {
        $booking = $this->importBooking(['peso_cotacao' => 1]);
        $this->mercadoria($booking, self::LCL);
        $ids = collect([41, 36, 291])->map(fn (int $ref) => $this->expense($booking, $ref, "D{$ref}", self::BL_UNIT, ['selling_rate' => 1]))->all();

        app(CalculateAgreedFreight::class)($booking, $ids, null, 7);

        $this->assertSame(1, DB::table('valor_frete_embarque')->count());
    }

    public function test_second_calculation_needs_a_justification_replaces_the_value_and_notifies_the_documentation(): void
    {
        $this->travelTo('2026-11-06 10:00');
        DB::table('usuario')->insert(['usuario_id' => 7, 'nome' => 'Ana']);
        DB::table('pessoa')->insert(['pessoa_id' => 30, 'nome' => 'Importadora <X>']);
        $booking = $this->importBooking(['pessoa_id_importador' => 30]);
        $id = $this->expense($booking, 1, 'Ocean Freight', self::BL_UNIT, ['selling_rate' => 1000]);
        app(CalculateAgreedFreight::class)($booking, [$id], null, 7);
        $this->assertSame(0, $this->sentSubjects()->count());

        DB::table('fin_expenses_values')->where('id', $id)->update(['selling_rate' => 1100]);

        try {
            app(CalculateAgreedFreight::class)($booking, [$id], ' ', 7);
            $this->fail('A segunda geração deveria exigir justificativa.');
        } catch (DomainException $e) {
            $this->assertSame('É necessário informar a justificativa!', $e->getMessage());
            $this->assertSame("\nOcean Freight / BL USD 1.000,00", DB::table('valor_frete_embarque')->value('valor'));
        }

        app(CalculateAgreedFreight::class)($booking, [$id], 'Reajuste do armador', 7);

        $this->assertSame(1, DB::table('valor_frete_embarque')->count());
        $this->assertSame(["\nOcean Freight / BL USD 1.100,00", 'Reajuste do armador'], [DB::table('valor_frete_embarque')->value('valor'), DB::table('valor_frete_embarque')->value('justificativa')]);
        $this->assertSame(['[AVISO] Atualização de valor House BL | BKG | Importadora <X>'], $this->sentSubjects()->all());
        $body = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('docimportacao@dmflog.com.br', $body->getTo()[0]->getAddress());
        $this->assertStringContainsString('Importadora &lt;X&gt;', $body->getHtmlBody());
        $this->assertStringContainsString('Reajuste do armador', $body->getHtmlBody());
        $this->assertStringContainsString('Ana', $body->getHtmlBody());
    }

    public function test_only_import_bookings_are_calculated(): void
    {
        $booking = $this->importBooking(['impor_expor' => 'e']);

        $this->expectExceptionMessage('O cálculo do frete vale só para importação marítima ou aérea.');

        app(CalculateAgreedFreight::class)($booking, [], null, 7);
    }

    /** @param  array<string, mixed>  $attributes */
    private function importBooking(array $attributes = []): Embarque
    {
        DB::table('cmn_currencies')->insertOrIgnore([['id' => 1, 'name' => 'Real', 'symbol' => 'R$'], ['id' => self::USD, 'name' => 'Dólar', 'symbol' => 'USD']]);
        DB::table('fin_expenses_unities')->insertOrIgnore([['id' => 1, 'name' => 'BL', 'acronym' => 'BL'], ['id' => 2, 'name' => 'Container', 'acronym' => 'CTN'], ['id' => 3, 'name' => 'W/M', 'acronym' => 'W/M'], ['id' => 4, 'name' => 'Outra', 'acronym' => 'OUT']]);

        return Embarque::find(DB::table('embarque')->insertGetId([...['booking' => 'BKG', 'impor_expor' => 'i', 'tipo_embarque' => 'm'], ...$attributes]));
    }

    private function mercadoria(Embarque $booking, int $containerType): void
    {
        DB::table('mercadoria')->insert(['embarque_id' => $booking->getKey(), 'tipo_container_id' => $containerType]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function expense(Embarque $booking, int $ref, string $name, int $unit, array $attributes = []): int
    {
        DB::table('fin_expenses')->insertOrIgnore(['id' => $ref, 'name' => $name, 'ref_unity' => $unit]);

        return DB::table('fin_expenses_values')->insertGetId(['ref_embarque' => $booking->getKey(), 'ref_expense' => $ref, 'ref_currency_sr' => self::USD, ...$attributes]);
    }
}

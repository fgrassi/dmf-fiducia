<?php

namespace Tests\Feature\Actions\Courier;

use App\Actions\Courier\CreateCourier;
use App\Actions\Courier\CreateCourierType;
use App\Actions\Courier\DeleteCourier;
use App\Actions\Courier\NextCourierNumber;
use App\Models\ControleCourier;
use DomainException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class CourierActionsTest extends TestCase
{
    use LegacySchema;

    public function test_each_request_spends_the_next_number_of_the_sequence(): void
    {
        $next = app(NextCourierNumber::class);

        $this->assertSame([1, 2], [$next(), $next()]);

        DB::table('sequencia_courrier')->insert(['sequencia' => 40]);

        $this->assertSame(41, $next());
        $this->assertSame(4, DB::table('sequencia_courrier')->count());
    }

    public function test_creates_the_courier_without_value_or_link(): void
    {
        $courier = app(CreateCourier::class)(['numero' => 'AWB-1', 'nota' => null, 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);

        $this->assertSame(['AWB-1', null, null], [$courier->fresh()->numero, $courier->fresh()->valor, $courier->fresh()->status_vinculo_nota]);
    }

    public function test_creates_the_type_with_a_trimmed_name(): void
    {
        $type = app(CreateCourierType::class)('  DHL ');

        $this->assertDatabaseHas('tipo_courier', ['tipo_courier_id' => $type->getKey(), 'nome' => 'DHL']);
    }

    public function test_removing_a_courier_removes_its_bookings_and_names_but_not_the_others(): void
    {
        $courier = $this->courier();
        $other = $this->courier();
        DB::table('ctr_courier_embarque')->insert([['controle_courier_id' => $courier->getKey(), 'embarque_id' => 1], ['controle_courier_id' => $other->getKey(), 'embarque_id' => 1]]);
        DB::table('ctr_courier_sem_embarque')->insert([['controle_courier_id' => $courier->getKey(), 'nome' => 'A'], ['controle_courier_id' => $other->getKey(), 'nome' => 'B']]);

        app(DeleteCourier::class)($courier);

        $this->assertSame([1, 1, 1], [ControleCourier::count(), DB::table('ctr_courier_embarque')->count(), DB::table('ctr_courier_sem_embarque')->count()]);
        $this->assertTrue($other->fresh()->exists);
    }

    public function test_refuses_to_remove_a_courier_whose_note_is_already_linked(): void
    {
        $courier = $this->courier(['status_vinculo_nota' => 's']);

        $this->expectException(DomainException::class);

        try {
            app(DeleteCourier::class)($courier);
        } finally {
            $this->assertTrue($courier->fresh()->exists);
        }
    }

    private function courier(array $attributes = []): ControleCourier
    {
        return ControleCourier::create($attributes + ['numero' => 'N1', 'data_recebimento' => '2026-10-06', 'tipo_courier_id' => 1, 'conta_utilizada' => 'd']);
    }
}

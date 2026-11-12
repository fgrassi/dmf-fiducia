<?php

namespace Tests\Feature\Models;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class EmbarqueConfirmationsTest extends TestCase
{
    use LegacySchema;

    public function test_departure_and_arrival_confirmations_are_kept_apart(): void
    {
        $booking = new Embarque;
        $booking->embarque_id = 1;
        $user = DB::table('usuario')->insertGetId(['nome' => 'Maria', 'email' => 'maria@x.com', 'login' => 'm', 'senha' => 'x', 'status' => 1]);
        DB::table('departure_confirmation')->insert(['ref_embarque' => 1, 'created_by' => $user, 'created_at' => '2026-11-01 10:00:00']);

        $this->assertSame(['Maria'], $booking->departureConfirmations()->with('createdBy')->get()->pluck('createdBy.nome')->all());
        $this->assertCount(0, $booking->arrivalConfirmations);
    }
}

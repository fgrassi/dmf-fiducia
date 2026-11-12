<?php

namespace Tests\Feature\Models;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LegacySchema;
use Tests\TestCase;

class EmbarqueContactsTest extends TestCase
{
    use LegacySchema;

    public function test_loads_the_contacts_of_each_party_from_the_booking(): void
    {
        $booking = new Embarque;
        $booking->embarque_id = 1;
        $user = DB::table('usuario')->insertGetId(['nome' => 'Maria', 'email' => 'maria@x.com', 'login' => 'm', 'senha' => 'x', 'status' => 1]);
        DB::table('embarque_contato_cliente')->insert(['embarque_id' => 1, 'usuario_id' => $user]);

        $this->assertSame(['maria@x.com'], $booking->clientContacts->pluck('email')->all());
        $this->assertCount(0, $booking->shipperContacts);
    }
}

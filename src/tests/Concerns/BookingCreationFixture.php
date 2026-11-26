<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Cadastros mínimos para criar uma reserva: cliente com contato padrão, agente, portos e vendedor. */
trait BookingCreationFixture
{
    protected User $author;

    protected int $client;

    protected int $agent;

    protected int $contact;

    protected int $origin;

    protected int $destination;

    protected int $seller;

    protected int $equipment;

    protected function buildBookingFixture(): void
    {
        $this->author = User::find($this->row('usuario', ['nome' => 'Ana', 'login' => 'ana', 'senha' => 'x', 'status' => 1, 'credentials' => 'booking', 'email' => 'ana@dmf.test', 'fone' => '51 9999']));
        $this->client = $this->row('pessoa', ['nome' => 'ACME']);
        $this->agent = $this->row('pessoa', ['nome' => 'AGENTE LTDA']);
        $this->row('pessoa_tipo_pessoa', ['pessoa_id' => $this->agent, 'tipo_pessoa_id' => 1]);
        $this->row('agente_pais', ['agente_id' => $this->agent, 'pais_id' => 25, 'restricao' => 0, 'created_by' => 1]);
        $this->contact = $this->contactOf($this->client, 7);
        $this->origin = $this->row('porto', ['nome' => 'SHANGHAI', 'uncode' => 'CNSHA', 'ref_pais' => 10, 'tipo' => 'p']);
        $this->destination = $this->row('porto', ['nome' => 'RIO GRANDE', 'uncode' => 'BRRIG', 'ref_pais' => 1, 'tipo' => 'p']);
        $office = $this->row('sedes', ['nome' => 'Sul', 'prefixo_imp' => 'IMP']);
        $this->seller = $this->row('vendedor', ['nome' => 'Vera', 'sede_id' => $office]);
        $this->equipment = $this->row('tipo_container', ['nome' => "20' DRY", 'tipo_embarque' => 'm']);
    }

    protected function contactOf(int $person, int $type, string $default = 's'): int
    {
        $user = $this->row('usuario', ['nome' => "Contato {$person}-{$type}", 'status' => 1, 'usuario_padrao' => $default]);
        $link = $this->row('pessoa_usuario', ['usuario_id' => $user, 'pessoa_id' => $person]);
        DB::table('pessoa_usuario_tipo_usuario')->insert(['pessoa_usuario_id' => $link, 'tipo_usuario_id' => $type]);

        return $user;
    }

    /** @param  array<string, mixed>  $values */
    protected function row(string $table, array $values): int
    {
        return DB::table($table)->insertGetId($values);
    }

    /** @return array<string, mixed> dados de uma importação marítima válida */
    protected function bookingData(array $overrides = []): array
    {
        return [
            'vendedor_id' => $this->seller,
            'data_topo' => today()->toDateString(),
            'booking' => 'IMP1-26',
            'cliente_id' => $this->client,
            'pessoa_id_representante' => $this->agent,
            'porto_origem_id' => $this->origin,
            'porto_destino_id' => $this->destination,
            'insurance' => 'n',
            'contacts' => ['cliente' => [$this->contact]],
            'goods' => [],
            ...$overrides,
        ];
    }
}

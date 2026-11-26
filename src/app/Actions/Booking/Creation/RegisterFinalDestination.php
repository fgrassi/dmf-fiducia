<?php

namespace App\Actions\Booking\Creation;

use Illuminate\Support\Facades\DB;

/** booking/gravarFinalDestination (legado): endereço de destino final do cliente, cadastrado de dentro da reserva. */
class RegisterFinalDestination
{
    /** Nos Estados Unidos o legado exige também estado, endereço e ZIP code. */
    public const UNITED_STATES = 4;

    /** @param  array{pais_id: int|string, estado_id?: int|string|null, cidade_id: int|string, endereco?: ?string, zip_code?: ?string}  $data */
    public function __invoke(array $data, int $clientId): int
    {
        return DB::table('destino_final')->insertGetId([...array_filter($data, fn ($value) => filled($value)), 'pessoa_id' => $clientId]);
    }

    /** @return array<int, string> destinos do cliente; fora dos EUA o endereço é opcional, então o rótulo leva cidade e país */
    public function options(mixed $clientId): array
    {
        return DB::table('destino_final as d')
            ->leftJoin('cidades as c', 'c.cidades_id', '=', 'd.cidade_id')
            ->leftJoin('paises as p', 'p.paises_id', '=', 'd.pais_id')
            ->where('d.pessoa_id', $clientId)
            ->orderBy('d.destino_final_id')
            ->get(['d.destino_final_id', 'd.endereco', 'c.nome as cidade', 'p.nome as pais'])
            ->mapWithKeys(fn (object $d) => [$d->destino_final_id => implode(' - ', array_filter([trim((string) $d->endereco), $d->cidade, $d->pais]))])
            ->all();
    }
}

<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;

/**
 * BlTable::getDefaultVessel / getDefaultRepresentation e o preenchimento do editAprovacaoBl (legado):
 * o que o BL traz da reserva enquanto o campo está vazio.
 */
class BlDefaults
{
    public const DESCRIPTION = '“AS PER LOCAL PORT AUTHORITIES REGULATIONS, OR CUSTOMS AUTHORITIES MAY RELEASE THE CARGO TO NOTIFY PARTY OR CONSIGNEE AT DESTINATION WITHOUT PRODUCTION OF ORIGINAL BILL/S OF LADING AND NOT INFORMING THE AGENCIES, WE ARE NOT BE RESPONSIBLE FOR SUCH DELIVERY, AND DO NOT ACCEPT ANY LIABILITY”';

    /** @return array<string, mixed> */
    public function __invoke(Embarque $booking): array
    {
        return [
            'vessel' => $this->vessel($booking),
            'port_lading' => $this->port($booking->porto_origem_id) ?: 'XXXXXXX',
            'port_disc' => $this->port($booking->porto_destino_id) ?: 'XXXXXXX',
            'place_delivery' => $this->placeOfDelivery($booking),
            'representation' => $this->representation($booking->pessoa_id_representante),
            'numImpressao' => Bl::DEFAULT_PRINTS,
            'habImpressao' => 0,
            'displayContainers' => 0,
            'place_date' => 'Santa Cruz do Sul, ',
            'desc_pkgs' => self::DESCRIPTION,
        ];
    }

    public function vessel(Embarque $booking): string
    {
        $ship = $booking->navio_id ? (string) DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome') : '';

        return $ship.' - '.$booking->vg;
    }

    private function port(?int $id): string
    {
        return $id ? (string) DB::table('porto')->where('porto_id', $id)->value('nome') : '';
    }

    /** O endereço do destino final cadastrado vale mais que o texto livre da reserva. */
    private function placeOfDelivery(Embarque $booking): string
    {
        $final = $booking->destino_final_id
            ? DB::table('destino_final as d')
                ->leftJoin('cidades as c', 'c.cidades_id', '=', 'd.cidade_id')
                ->leftJoin('paises as p', 'p.paises_id', '=', 'd.pais_id')
                ->where('d.destino_final_id', $booking->destino_final_id)
                ->first(['d.endereco', 'c.nome as cidade', 'p.nome as pais'])
            : null;

        $address = $final ? collect([$final->endereco, $final->cidade, $final->pais])->filter()->implode(', ') : '';

        return $address ?: ($booking->place_of_delivery ?: 'XXXXXXXXXXXXXXXX');
    }

    private function representation(?int $agentId): string
    {
        $agent = $agentId
            ? DB::table('pessoa as p')
                ->leftJoin('cidades as c', 'c.cidades_id', '=', 'p.cidades_id')
                ->leftJoin('estados as e', 'e.estados_id', '=', 'c.estados_id')
                ->leftJoin('paises as pa', 'pa.paises_id', '=', 'e.paises_id')
                ->where('p.pessoa_id', $agentId)
                ->first(['p.razao_social', 'p.endereco', 'p.fone1', 'p.fone2', 'p.cel', 'p.fax', 'c.nome as cidade', 'pa.nome as pais'])
            : null;

        if (! $agent) {
            return '';
        }

        return collect([
            [$agent->razao_social, "\n"],
            [$agent->endereco, "\n"],
            [$agent->fone1, "\n", 'PH: '],
            [$agent->fone2, "\n", 'PH: '],
            [$agent->cel, "\n", 'Cel: '],
            [$agent->fax, "\n", 'Fax: '],
            [$agent->cidade, ', '],
            [$agent->pais, "\n"],
        ])->filter(fn (array $part) => filled($part[0]))
            ->map(fn (array $part) => ($part[2] ?? '').$part[0].$part[1])
            ->implode('');
    }
}

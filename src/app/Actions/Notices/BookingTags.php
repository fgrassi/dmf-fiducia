<?php

namespace App\Actions\Notices;

use Illuminate\Support\Facades\DB;

/**
 * Valores das variáveis prontas da reserva (reserva, mbl, hbl, shipper, importador, agente...), para os avisos que falam de uma
 * reserva só. Shipper é o cliente da reserva (exportador), como o Controle PA chama.
 */
class BookingTags
{
    /** Variáveis prontas (nome => [rótulo, exemplo]); os avisos de uma reserva só as oferecem no editor. */
    public const TAGS = [
        'reserva' => ['Reserva (booking)', 'BKG123'],
        'mbl' => ['MBL', 'MBL123'],
        'hbl' => ['HBL', 'HBL123'],
        'shipper' => ['Shipper (exportador, cliente da reserva)', 'Exportador Exemplo'],
        'importador' => ['Importador', 'Importador Exemplo'],
        'agente' => ['Agente', 'Agente Exemplo'],
        'armador' => ['Armador', 'MAERSK'],
        'porto_origem' => ['Porto de origem', 'Santos'],
        'porto_destino' => ['Porto de destino', 'Rotterdam'],
        'data_saida' => ['Saída prevista (ETD)', '20/11/2026'],
        'data_chegada' => ['Chegada prevista (ETA)', '20/12/2026'],
    ];

    /** @return array<string, string> */
    public function __invoke(int $bookingId): array
    {
        $b = DB::table('embarque as e')
            ->leftJoin('pessoa as sh', 'sh.pessoa_id', '=', 'e.cliente_id')
            ->leftJoin('pessoa as imp', 'imp.pessoa_id', '=', 'e.pessoa_id_importador')
            ->leftJoin('pessoa as ag', 'ag.pessoa_id', '=', 'e.pessoa_id_representante')
            ->leftJoin('armador as ar', 'ar.armador_id', '=', 'e.armador_id')
            ->leftJoin('porto as pol', 'pol.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('porto as pod', 'pod.porto_id', '=', 'e.porto_destino_id')
            ->where('e.embarque_id', $bookingId)
            ->first(['e.booking', 'e.master_bl', 'e.house_bl', 'e.saida_prevista', 'e.chegada_prevista', 'sh.nome as shipper', 'imp.nome as importador', 'ag.nome as agente', 'ar.nome as armador', 'pol.nome as porto_origem', 'pod.nome as porto_destino']);

        return [
            'reserva' => (string) $b?->booking,
            'mbl' => (string) $b?->master_bl,
            'hbl' => (string) $b?->house_bl,
            'shipper' => (string) $b?->shipper,
            'importador' => (string) $b?->importador,
            'agente' => (string) $b?->agente,
            'armador' => (string) $b?->armador,
            'porto_origem' => (string) $b?->porto_origem,
            'porto_destino' => (string) $b?->porto_destino,
            'data_saida' => $this->date($b?->saida_prevista),
            'data_chegada' => $this->date($b?->chegada_prevista),
        ];
    }

    private function date(?string $value): string
    {
        return filled($value) && ! str_starts_with($value, '0000') ? date('d/m/Y', strtotime($value)) : '';
    }
}

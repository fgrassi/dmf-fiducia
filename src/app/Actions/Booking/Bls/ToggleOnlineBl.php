<?php

namespace App\Actions\Booking\Bls;

use App\Models\Bl;
use App\Models\Container;
use Illuminate\Support\Facades\DB;

/**
 * habilitaDesabilita (legado): o switch ABERTO/FECHADO do BL online. Fechado ("f") volta a aberto ("a") sem mexer em nada.
 * Aberto fecha o BL: os dados e os containers do draft online voltam a ser o conteúdo do BL.
 */
class ToggleOnlineBl
{
    public function __invoke(Bl $bl): void
    {
        if ($bl->status_bl_online === 'f') {
            $bl->forceFill(['status_bl_online' => 'a'])->save();

            return;
        }

        $online = DB::table('bl_online')->where('bl_online_id', $bl->bl_online_id)->first();

        DB::transaction(function () use ($bl, $online) {
            $bl->forceFill([
                'shipper' => $online->shipper,
                'notify' => $online->notify,
                'consignee' => $online->consignee,
                'rnc' => $online->rnc,
                'vessel' => $online->vessel,
                'port_trans' => $online->port_trans,
                'place_delivery' => $online->place_delivery,
                'port_lading' => $online->port_lading,
                'port_disc' => $online->port_disc,
                'mark_nos' => $online->mark_nos,
                'desc_pkgs' => $online->desc_pkgs,
                'num_original' => $online->num_original,
                'gross_weight' => $online->gross_weight,
                'net_weight' => $online->net_weight,
                'volume' => $online->volume,
                'status_bl_online' => 'f',
            ])->save();

            Container::where('bl_id', $bl->getKey())->delete();

            foreach (DB::table('container_online')->where('bl_online_id', $bl->bl_online_id)->get() as $source) {
                Container::create([
                    'bl_id' => $bl->getKey(),
                    'tipo_container_id' => $source->tipo_container_id,
                    'numero' => $source->numero,
                    'tara' => $source->tara,
                    'seals_n1' => $source->seals_n1,
                    'seals_n2' => $source->seals_n2,
                    'seals_n3' => $source->seals_n3,
                    'qty_pkgs' => $source->qty_pkgs,
                    'gross_weight' => $source->gross_weight,
                    'net_weight' => $source->net_weight,
                    'volume' => $source->volume,
                ]);
            }
        });
    }
}

<?php

namespace App\Actions\Carriers;

use App\Models\Armador;

/** armador/update (legado): grava o cadastro e o de-para do Portal Integrador (importação e exportação) nas colunas JSON. */
class SaveCarrier
{
    /** @param  array<string, mixed>  $data  campos do armador + portal_refs[impo|expo][parâmetro] = origem */
    public function __invoke(Armador $carrier, array $data): Armador
    {
        $portalRefs = $data['portal_refs'] ?? [];
        unset($data['portal_refs']);

        $carrier->fill($data);
        $carrier->integracao_portal_refs_impo = PortalReferenceMap::encode($portalRefs['impo'] ?? []);
        $carrier->integracao_portal_refs_expo = PortalReferenceMap::encode($portalRefs['expo'] ?? []);
        $carrier->save();

        return $carrier;
    }
}

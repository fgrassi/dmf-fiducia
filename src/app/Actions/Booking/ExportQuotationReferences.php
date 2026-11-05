<?php

namespace App\Actions\Booking;

use Illuminate\Support\Facades\DB;

/** CotacaoExpo::getReferencia (legado): iniciais do usuário + código com 4 dígitos + portos (UN/LOCODE sem o país) + ano. */
class ExportQuotationReferences
{
    /**
     * @param  list<int|null>  $ids  ids de cotacao_expo
     * @return array<int, string> id => referência (cotação inexistente fica de fora)
     */
    public function __invoke(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));

        if ($ids === []) {
            return [];
        }

        return DB::table('cotacao_expo as q')
            ->leftJoin('usuario as u', 'u.usuario_id', '=', 'q.created_by')
            ->leftJoin('porto as o', 'o.porto_id', '=', 'q.porto_origem_id')
            ->leftJoin('porto as d', 'd.porto_id', '=', 'q.porto_destino_id')
            ->whereIn('q.cotacao_expo_id', $ids)
            ->get(['q.cotacao_expo_id as id', 'q.created_at', 'u.nome as user', 'o.uncode as origin', 'd.uncode as destination'])
            ->mapWithKeys(fn (object $q) => [$q->id => $this->initials((string) $q->user)
                .str_pad((string) $q->id, 4, '0', STR_PAD_LEFT)
                .substr((string) $q->origin, 2)
                .substr((string) $q->destination, 2)
                .'/'.date('Y', strtotime((string) $q->created_at))])
            ->all();
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->reject(fn (string $word) => ctype_lower(substr($word, 0, 1)))
            ->map(fn (string $word) => substr($word, 0, 1))
            ->implode('');
    }
}

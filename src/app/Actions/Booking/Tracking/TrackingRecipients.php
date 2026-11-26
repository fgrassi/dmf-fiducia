<?php

namespace App\Actions\Booking\Tracking;

use App\Actions\Booking\SellerRestriction;
use App\Models\Embarque;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Embarque::getContatosTracking (legado): quem recebe o e-mail de rastreamento da reserva, cada contato com o seu tipo
 * (Vendedor, Cliente, Consignee, Notify, Shipper, Despachante, Trading, Assessoria ou Agente).
 * Como o legado, uma reserva de vendedor com restrição ainda sem cadastro de autorização passa a ter a restrição pendente.
 */
class TrackingRecipients
{
    /** @return list<array{nome: ?string, email: ?string, tipo: string, agente_contato_id?: int}> */
    public function __invoke(Embarque $booking): array
    {
        $contacts = [];
        $add = function (iterable $rows, string $type, string $name = 'nome') use (&$contacts) {
            foreach ($rows as $row) {
                $contacts[] = ['nome' => $row->{$name}, 'email' => $row->email, 'tipo' => $type] + (isset($row->agente_contato_id) ? ['agente_contato_id' => (int) $row->agente_contato_id] : []);
            }
        };

        $seller = DB::table('vendedor')->where('vendedor_id', $booking->vendedor_id)->first();

        if ($seller && $seller->receber_avisos === 's' && filled($seller->email) && (int) $seller->ctr_ferias === 0 && app(SellerRestriction::class)($booking)) {
            $add([$seller], 'Vendedor');
        }

        foreach (['embarque_contato_cliente' => 'Cliente', 'embarque_contato_importador' => 'Consignee', 'embarque_contato_notify' => 'Notify', 'embarque_contato' => 'Shipper'] as $table => $type) {
            $add(DB::table("{$table} as ec")
                ->join('usuario as u', 'u.usuario_id', '=', 'ec.usuario_id')
                ->where('ec.embarque_id', $booking->getKey())
                ->where('u.receber_avisos', 's')
                ->where('u.status', 1)
                ->get(['u.nome', 'u.email']), $type);
        }

        if ($booking->despachante_id) {
            $add($this->brokerContacts('pessoa_contato_despachante', 'pessoa_id', $booking->cliente_id, $booking->despachante_id)
                ->where('c.impor_expor', $booking->impor_expor)
                ->get(['c.name', 'c.email']), 'Despachante (Cliente)', 'name');

            if ($booking->trading_id) {
                $add($this->brokerContacts('trading_contato_despachante', 'trading_id', $booking->trading_id, $booking->despachante_id)
                    ->get(['c.name', 'c.email']), 'Despachante (Trading)', 'name');
            }
        }

        foreach ([['trading_id', 'pessoa_contato_trading', 'trading_contato', 'trading_contato_id', 'Trading'], ['assessoria_id', 'pessoa_contato_assessoria', 'assessoria_contato', 'assessoria_contato_id', 'Assessoria']] as [$column, $link, $contactTable, $key, $type]) {
            if ($booking->{$column}) {
                $add(DB::table("{$link} as l")
                    ->join("{$contactTable} as c", "c.{$key}", '=', "l.{$key}")
                    ->where('l.pessoa_id', $booking->cliente_id)
                    ->where("l.{$column}", $booking->{$column})
                    ->where('c.status', 1)
                    ->whereIn('l.tipo', ['t', 'a'])
                    ->get(['c.nome', 'c.email']), $type);
            }
        }

        if ($booking->impor_expor === 'e' && $booking->pessoa_id_representante) {
            $excluded = DB::table('embarque_agente_contato_excluido')->where('embarque_id', $booking->getKey())->pluck('agente_contato_id');
            $add(DB::table('agente_contato')
                ->where('ref_pessoa', $booking->pessoa_id_representante)
                ->whereNotIn('id', $excluded)
                ->where(fn ($q) => $q->whereIn('tipo', ['t', 'b', ''])->orWhereNull('tipo'))
                ->whereIn('operacao', ['e', 'a'])
                ->where(fn ($q) => $q->whereNull('porto_id')->orWhere('porto_id', 0)->orWhere('porto_id', $booking->porto_destino_id))
                ->get(['id as agente_contato_id', 'name', 'email']), 'Agente', 'name');
        }

        return $contacts;
    }

    /** Contatos de despachante ativos do tipo "t" (tracking) ou "a" (todos) ligados ao cliente ou à trading. */
    private function brokerContacts(string $link, string $ownerColumn, mixed $owner, mixed $brokerId): Builder
    {
        return DB::table("{$link} as l")
            ->join('despachante_contato as c', 'c.id', '=', 'l.despachante_contato_id')
            ->where("l.{$ownerColumn}", $owner)
            ->where('l.despachante_id', $brokerId)
            ->whereColumn('c.ref_despachante', 'l.despachante_id')
            ->where('c.status', 1)
            ->whereIn('l.tipo', ['t', 'a']);
    }
}

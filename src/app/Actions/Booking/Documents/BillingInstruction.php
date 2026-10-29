<?php

namespace App\Actions\Booking\Documents;

use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Dados da instrução de cobrança da reserva (DmfUtil::geraPdfBilling, legado): quem paga, rota, mercadorias, despachantes
 * e as despesas combinadas (B/R, S/R, tipo e Due To). O PDF só desenha o que sai daqui.
 *
 * Preserva um defeito do legado: o e-mail do despachante do cliente só aparece se a reserva tiver despachante DMF
 * (o legado testa um e busca o outro).
 */
class BillingInstruction
{
    private const BILLING = ['c' => 'CC', 'p' => 'PP', 'a' => 'PA', 'f' => 'PA', 's' => 'SEG'];

    private const INLAND = [1 => 'Armador', 2 => 'Agente'];

    private const CLOSING = ['o' => 'Origem / DMF', 'd' => 'Destino / Agente'];

    private const LOADING = ['f' => 'Free Hand', 'd' => 'DMF'];

    private const YES_NO = ['s' => 'YES', 'n' => 'NO'];

    private const CONTACT_TABLES = [
        'embarque_contato_cliente' => 'Cliente',
        'embarque_contato_importador' => 'Consignee',
        'embarque_contato_notify' => 'Notify',
        'embarque_contato' => 'Shipper',
    ];

    /** @return array<string, mixed> */
    public function __invoke(Embarque $booking): array
    {
        $maritime = $booking->tipo_embarque === 'm';
        $export = $booking->impor_expor === 'e';
        $person = fn (?int $id) => $id ? (string) DB::table('pessoa')->where('pessoa_id', $id)->value('nome') : '';
        $importer = $booking->pessoa_id_importador ? DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_importador)->first(['nome', 'cnpj']) : null;
        $destination = $booking->porto_destino_id ? DB::table('porto')->where('porto_id', $booking->porto_destino_id)->first() : null;

        return [
            'type' => ($maritime ? 'OCEAN ' : 'AIR ').($export ? 'EXPORT' : 'IMPORT'),
            'maritime' => $maritime,
            'export' => $export,
            'contact' => (string) $booking->de,
            'updated_at' => now()->format('d/m/Y'),
            'seller' => $booking->vendedor_id ? (string) DB::table('vendedor')->where('vendedor_id', $booking->vendedor_id)->value('nome') : '',
            'shipment' => self::LOADING[$booking->loading_type] ?? '',
            'consignee' => $importer ? "{$importer->nome} (CNPJ: {$importer->cnpj})" : '',
            'notify' => $person($booking->pessoa_id_notify),
            'shipper' => $person($booking->pessoa_id_cliente),
            'contacts' => $this->contacts($booking),
            'agent' => $person($booking->pessoa_id_representante),
            'origin' => $this->port($booking->porto_origem_id),
            'destination' => $this->port($booking->porto_destino_id),
            'delivery' => $this->delivery($booking),
            'carrier' => $maritime ? $this->carrier($booking) : (string) DB::table('cia_aerea')->where('id', $booking->ref_cia_aerea)->value('name'),
            'free_time' => $booking->free_time,
            'booking' => (string) $booking->booking,
            'goods' => $this->goods($booking),
            'inland' => self::INLAND[(int) $booking->inland] ?? '',
            'closing' => self::CLOSING[$booking->tipo_fechamento] ?? '',
            'incoterm' => (string) $booking->incoterm_emb,
            'invoice' => (string) $booking->invoice,
            'customer_ref' => (string) $booking->customer_ref,
            'quotation' => $this->quotation($booking, $export),
            'notes' => (string) $booking->embarque_obs,
            'special' => $export && $destination ? (string) DB::table('instrucao_especial')->where('paises_id', $destination->ref_pais)->orderByDesc('instrucao_especial_id')->value('instrucao') : null,
            'costs' => $this->costs($booking),
            'deposit' => self::YES_NO[$booking->garantee] ?? (string) $booking->garantee,
            'insurance' => self::YES_NO[$booking->insurance] ?? (string) $booking->insurance,
            'dmf_broker' => $booking->despachante_dmf_id ? (string) DB::table('despachante')->where('despachante_id', $booking->despachante_dmf_id)->value('nome') : '',
            'client_broker' => $booking->despachante_id ? (string) DB::table('despachante')->where('despachante_id', $booking->despachante_id)->value('nome') : '',
            'client_broker_email' => $booking->despachante_dmf_id ? (string) DB::table('despachante')->where('despachante_id', $booking->despachante_id)->value('email') : '',
            'broker_contacts' => $this->brokerContacts($booking),
        ];
    }

    /** Embarque::getArmadorParaPagamento: o coloader, se informado, senão o armador da reserva. */
    private function carrier(Embarque $booking): string
    {
        $id = (int) $booking->coloader_id > 0 ? (int) $booking->coloader_id : $booking->armador_id;

        return $id ? (string) DB::table('armador')->where('armador_id', $id)->value('nome') : '';
    }

    /** Porto::__toString: nome (país)(uncode). */
    private function port(?int $id): string
    {
        $port = $id ? DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $id)->first(['p.nome', 'p.uncode', 'c.uncode as country']) : null;

        return $port ? "{$port->nome} ({$port->country})({$port->uncode})" : '';
    }

    /** DestinoFinal::getEnderecoCompleto; sem endereço cadastrado, o texto livre da reserva. */
    private function delivery(Embarque $booking): string
    {
        $final = $booking->destino_final_id ? DB::table('destino_final')->where('destino_final_id', $booking->destino_final_id)->first() : null;

        if ($final) {
            $country = DB::table('paises')->where('paises_id', $final->pais_id)->first(['nome', 'uncode']);
            $country = $country ? $country->nome.(filled($country->uncode) ? " ({$country->uncode})" : '') : '';
            $city = (string) DB::table('cidades')->where('cidades_id', $final->cidade_id)->value('nome');
            $state = (string) DB::table('estados')->where('estados_id', $final->estado_id)->value('nome');

            $full = filled($final->endereco)
                ? collect([$final->endereco, $final->zip_code, $city, $state, $country])->filter(fn ($part) => filled($part))->implode(', ')
                : (filled($country) ? implode(', ', array_filter([$country, $city], fn ($part) => filled($part))) : '');

            if (filled($full)) {
                return $full;
            }
        }

        return (string) $booking->place_of_delivery;
    }

    /** "2 x 40HC | ...", com peso bruto e cobrado nas reservas aéreas. */
    private function goods(Embarque $booking): string
    {
        return DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $booking->getKey())
            ->orderBy('m.nome')
            ->get(['m.quantidade', 'm.gross_weight', 'm.net_weight', 't.nome as equipment'])
            ->map(fn (stdClass $m) => "{$m->quantidade} x {$m->equipment}"
                .($booking->tipo_embarque === 'a' ? ' - GW: '.number_format((float) $m->gross_weight, 3, ',', '.').' - CW: '.number_format((float) $m->net_weight, 3, ',', '.') : ''))
            ->implode('  |  ');
    }

    /** Referência da cotação: a da importação é gravada; a da exportação é montada (CotacaoExpo::getReferencia). */
    private function quotation(Embarque $booking, bool $export): string
    {
        if (! $export) {
            return (string) DB::table('cotacao_impo')->where('cotacao_impo_id', $booking->cotacao_impo_id)->value('referencia');
        }

        $quotation = DB::table('cotacao_expo')->where('cotacao_expo_id', $booking->cotacao_expo_id)->first();

        if (! $quotation) {
            return '';
        }

        $initials = collect(explode(' ', (string) DB::table('usuario')->where('usuario_id', $quotation->created_by)->value('nome')))
            ->reject(fn (string $word) => ctype_lower(substr($word, 0, 1)))
            ->map(fn (string $word) => substr($word, 0, 1))
            ->implode('');
        $code = fn (?int $port) => substr((string) DB::table('porto')->where('porto_id', $port)->value('uncode'), 2);

        return $initials.str_pad((string) $quotation->cotacao_expo_id, 4, '0', STR_PAD_LEFT).$code($quotation->porto_origem_id).$code($quotation->porto_destino_id).'/'.date('Y', strtotime((string) $quotation->created_at));
    }

    /** Embarque::getContatosDocs: contatos ativos que recebem documentos, "nome (e-mail) - Phone: fone". */
    private function contacts(Embarque $booking): string
    {
        $lines = [];

        foreach (array_keys(self::CONTACT_TABLES) as $table) {
            foreach (DB::table("{$table} as c")->join('usuario as u', 'u.usuario_id', '=', 'c.usuario_id')
                ->where('c.embarque_id', $booking->getKey())->where('u.receber_documentos', 's')->where('u.status', 1)
                ->orderBy('c.usuario_id')->get(['u.nome', 'u.email', 'u.fone']) as $user) {
                $lines[] = "{$user->nome} ({$user->email}) - Phone: {$user->fone}";
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<array{name: string, email: string, value: string, billing: string, due_to: string}> */
    private function costs(Embarque $booking): array
    {
        return DB::table('fin_expenses_values as v')
            ->join('fin_expenses as e', 'e.id', '=', 'v.ref_expense')
            ->leftJoin('fin_expenses_unities as u', 'u.id', '=', 'e.ref_unity')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'v.ref_equipment')
            ->leftJoin('bl', 'bl.bl_id', '=', 'v.ref_bl')
            ->leftJoin('container as c', 'c.container_id', '=', 'v.ref_container')
            ->leftJoin('bl as cbl', 'cbl.bl_id', '=', 'c.bl_id')
            ->leftJoin('fin_expenses_values_due_to as d', 'd.id', '=', 'v.ref_due_to')
            ->leftJoin('cmn_currencies as cb', 'cb.id', '=', 'v.ref_currency_br')
            ->leftJoin('cmn_currencies as cs', 'cs.id', '=', 'v.ref_currency_sr')
            ->where('v.ref_embarque', $booking->getKey())
            ->orderBy('v.id')
            ->get(['v.buying_rate', 'v.selling_rate', 'v.all_in', 'v.billing', 'v.ref_bl', 'v.ref_container', 'e.name', 'u.acronym', 't.nome as equipment', 'bl.bl as bl_name', 'c.numero', 'cbl.bl as container_bl', 'd.name as due_to', 'cb.symbol as br_symbol', 'cs.symbol as sr_symbol'])
            ->map(fn (stdClass $c) => [
                'name' => "{$c->name} / {$c->acronym}"
                    .(filled($c->equipment) ? " ({$c->equipment})" : '')
                    .($c->ref_bl ? " ( BL: {$c->bl_name})" : '')
                    .($c->ref_container ? ' ('.($c->numero ? "{$c->numero} ({$c->container_bl})" : '').')' : ''),
                'buying' => trim("{$c->br_symbol} ".number_format((float) $c->buying_rate, 2, ',', '.')),
                'selling' => (float) $c->selling_rate <= 0 && (string) $c->all_in === '1' ? 'INCLUSO' : trim("{$c->sr_symbol} ".number_format((float) $c->selling_rate, 2, ',', '.')),
                'billing' => self::BILLING[$c->billing] ?? '',
                'due_to' => (string) $c->due_to,
            ])
            ->all();
    }

    /** Contatos ativos do despachante do cliente ligados ao cliente da reserva. @return list<string> */
    private function brokerContacts(Embarque $booking): array
    {
        if (! $booking->despachante_id) {
            return [];
        }

        return DB::table('despachante_contato as dc')
            ->join('pessoa_contato_despachante as p', 'p.despachante_contato_id', '=', 'dc.id')
            ->where('dc.ref_despachante', $booking->despachante_id)
            ->where('dc.status', 1)
            ->where('p.pessoa_id', $booking->cliente_id)
            ->orderBy('dc.id')
            ->get(['dc.name', 'dc.email'])
            ->map(fn (stdClass $c) => "{$c->name} ({$c->email})")
            ->all();
    }
}

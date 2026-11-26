<?php

namespace App\Actions\Booking\Creation;

use App\Models\Embarque;
use App\Models\Porto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * setRastreamento (legado): a foto da reserva que vai para o rastreamento ("Atualizado: Reserva"), com os mesmos títulos e a
 * mesma ordem. Diferença do legado, por segurança: os valores são escapados.
 */
class BookingSnapshot
{
    /** EmbarqueTable::$finalizado_nomes (legado, em inglês). */
    private const STATUSES = ['n' => 'Open', 'p' => 'Suspenso', 's' => 'Arrived at Destination', 'e' => 'Closed', 'c' => 'Canceled', 'd' => 'Discarded'];

    private const GUARANTEES = ['n' => 'NO', 's' => 'YES', '3' => '30 DAYS', '4' => '45 DAYS', '5' => '50 DAYS', '6' => '60 DAYS', '9' => '90 DAYS'];

    private const YES_NO = ['s' => 'SIM', 'n' => 'NÃO'];

    public function __invoke(Embarque $booking): string
    {
        $b = (object) (array) DB::table('embarque')->where('embarque_id', $booking->getKey())->first();
        $name = fn (string $table, string $key, mixed $id, string $column = 'nome') => $id ? DB::table($table)->where($key, $id)->value($column) : null;
        $person = fn (mixed $id) => $name('pessoa', 'pessoa_id', $id);
        $date = fn (?string $value) => filled($value) && ! str_starts_with($value, '0000') ? Carbon::parse($value)->format('d/m/Y') : '';

        return '<h3>Informacões da Reserva</h3>'
            .$this->lines([
                'Vendedor' => $name('vendedor', 'vendedor_id', $b->vendedor_id),
                'Situacao' => self::STATUSES[$b->finalizado] ?? '',
                'Tipo de Reserva' => $b->impor_expor === 'i' ? 'Importação' : 'Exportação',
                'Data de solicitacão' => $date($b->data_topo),
                'Agente' => $person($b->pessoa_id_representante),
                ($b->impor_expor === 'e' ? 'Responsável pelo Fechamento' : 'Responsável') => $b->de,
                'Fone' => $b->de_fone,
                'Observacão' => $b->observacaoint,
                'Booking' => $b->booking,
                'Invoice' => $b->invoice,
                'Customer Reference' => $b->customer_ref,
                'House BL' => $b->house_bl,
                'Qtd House BL' => $b->qtd_bl,
                'Master BL' => $b->master_bl,
                'Nr. do(s) Container(s)' => $b->nr_container,
                'Ce Mercante' => $b->ce_mercante,
                'Validade Cotacão' => $date($b->data_validade) ?: 'n/a',
            ])
            .'<h3>Informacões do Embarque</h3>'
            .$this->lines([
                'Navio' => $name('navio', 'navio_id', $b->navio_id),
                'Navio Destino' => $name('navio', 'navio_id', $b->navio_id_destino),
                'VG' => $b->vg,
                'ETD' => $date($b->saida_prevista),
                'ETA' => $date($b->chegada_prevista),
                'Saida Efetiva' => $date($b->saida_efetiva),
                'Chegada Efetiva' => $date($b->chegada_efetiva),
                'Tipo de Embarque' => ['d' => 'DMF', 'f' => 'Free Hand'][$b->loading_type] ?? '',
                'Armador' => $name('armador', 'armador_id', $b->armador_id),
                'Cia Aerea' => $name('cia_aerea', 'id', $b->ref_cia_aerea, 'name'),
                'Incoterm do embarque' => $name('incoterm', 'incoterm_id', $b->incoterm_id),
                'Obs. Embarque' => $b->embarque_obs,
                'Origem' => Porto::find($b->porto_origem_id)?->label,
                'Destino' => Porto::find($b->porto_destino_id)?->label,
                'Via' => $b->via,
                'Free Time Demurrage' => $b->free_time,
                'Free Time Detention' => $b->free_time_detention,
                'Security Deposit' => self::GUARANTEES[$b->garantee] ?? '',
                'Seguro Contratado' => self::YES_NO[$b->insurance] ?? '',
                'Gerou Demurrage' => self::YES_NO[$b->demurrage] ?? '',
                'Dias de Demurrage' => $b->demurrage_days,
            ])
            .'<h3>Informacões do Importador</h3>'
            .$this->lines(['Importador' => $person($b->pessoa_id_importador)])
            .$this->contacts('embarque_contato_importador', $b->embarque_id)
            .'<h3>Informacões do Exportador</h3>'
            .$this->lines(['Exportador' => $person($b->pessoa_id_cliente)])
            .$this->contacts('embarque_contato', $b->embarque_id)
            .'<h3>Informacões do Despachante</h3>'
            .$this->lines([
                'Customer Broker' => $name('despachante', 'despachante_id', $b->despachante_id),
                'DMF Broker' => $name('despachante', 'despachante_id', $b->despachante_dmf_id),
                'Representante' => $name('representante', 'representante_id', $b->representante_id),
            ])
            .'<h3>Informacões da Mercadoria</h3>'
            .$this->goods($b->embarque_id)
            .'<h3>Informacões Gerais</h3>'
            .$this->lines([
                'Dead line Draft' => $b->dead_line_draft,
                'Dead line VGM' => $b->dead_line_vgm,
                'Dead line Carga' => $b->dead_line_carga,
                ...$this->imoDeadline($b),
                'Prontidão' => $date($b->prontidao),
                'Local de retirada dos containers' => $b->local_retirada,
                'Local de entrega dos containers' => $b->local_entrega,
                'Comentários' => $b->comentarios,
            ]);
    }

    /** @param  array<string, mixed>  $lines */
    private function lines(array $lines): string
    {
        return collect($lines)
            ->map(fn (mixed $value, string $label) => "<p>{$label}: ".nl2br(e((string) $value)).'</p>')
            ->implode('');
    }

    /** O texto livre "Dead line IMO"; sem ele, a data. Sem nenhum dos dois, a linha não aparece. */
    private function imoDeadline(object $booking): array
    {
        $value = $booking->lista_embarque
            ?: ($booking->dead_line_imo ? Carbon::parse($booking->dead_line_imo)->format('d/m/Y H:i:s') : null);

        return $value ? ['Dead line IMO' => $value] : [];
    }

    private function contacts(string $table, int $bookingId): string
    {
        $items = DB::table("{$table} as c")
            ->join('usuario as u', 'u.usuario_id', '=', 'c.usuario_id')
            ->where('c.embarque_id', $bookingId)
            ->orderBy('c.embarque_contato_id')
            ->get(['u.nome', 'u.email'])
            ->map(fn (object $u) => '<li>'.e("{$u->nome}({$u->email})").'</li>')
            ->implode('');

        return "<ul>{$items}</ul>";
    }

    private function goods(int $bookingId): string
    {
        $columns = ['gross_weight', 'net_weight', 'cubature', 'width', 'height', 'depth'];
        $goods = DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $bookingId)
            ->orderBy('m.mercadoria_id')
            ->get(['m.*', 't.nome as equipamento']);
        $number = fn (mixed $value) => number_format((float) $value, 4, ',', '.');
        $row = fn (array $cells) => '<tr>'.implode('', array_map(fn ($cell) => '<td>'.e((string) $cell).'</td>', $cells)).'</tr>';
        $header = '<tr><td></td>'.implode('', array_map(
            fn (string $title) => "<th align=\"left\">{$title}</th>",
            ['Mercadoria', 'Quantidade', 'Equipamento', 'Gross Weight', 'Net Weight', 'Peso Volumétrico', 'Width', 'Heigth', 'Dept h'],
        )).'</tr>';

        return '<table cellpadding="0" cellspacing="0" border="0" class="table_1">'.$header
            .$goods->map(fn (object $g) => $row(['', $g->nome, $g->quantidade, $g->equipamento, ...array_map(fn (string $c) => $number($g->{$c}), $columns)]))->implode('')
            .$row(['TOTAL:', '', $number($goods->sum(fn (object $g) => (float) $g->quantidade)), '', ...array_map(fn (string $c) => $number($goods->sum(fn (object $g) => (float) $g->{$c})), $columns)])
            .'</table>';
    }
}

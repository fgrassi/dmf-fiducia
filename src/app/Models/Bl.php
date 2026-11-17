<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** House BL da reserva (bl, legado): os dados que saem impressos no conhecimento de embarque. */
class Bl extends Model
{
    public const OPEN = 'aberto';

    public const APPROVED = 'aprovado';

    /** numImpressao: quantas vezes o original pode ser impresso, se ninguém definir. */
    public const DEFAULT_PRINTS = 3;

    /** Freight payable at (freight_at, legado). */
    public const FREIGHT_AT = [
        'ORIGIN' => 'ORIGIN',
        'DESTINATION' => 'DESTINATION',
        'ABROAD' => 'ABROAD',
    ];

    protected $table = 'bl';

    protected $primaryKey = 'bl_id';

    public $timestamps = false;

    protected $guarded = [];

    public function embarque(): BelongsTo
    {
        return $this->belongsTo(Embarque::class, 'embarque_id', 'embarque_id');
    }

    /** Bl::getFatorLcl (legado): o maior entre o peso (em toneladas) e o volume do BL. */
    public function lclFactor(): float
    {
        $number = fn (?string $value): float => (float) str_replace(',', '.', str_replace('.', '', (string) $value));

        return max($number($this->gross_weight) / 1000, $number($this->volume));
    }

    /** getBlRastreabilidade (legado): foto do BL (dados e containers) que fica no rastreamento da reserva. */
    public function trackingHistory(): string
    {
        $text = fn (?string $value): string => nl2br(e((string) $value));
        $booking = DB::table('embarque')->where('embarque_id', $this->embarque_id)->value('booking');

        $rows = DB::table('container as c')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'c.tipo_container_id')
            ->where('c.bl_id', $this->getKey())
            ->get(['t.nome as tipo', 'c.*'])
            ->map(fn (object $c): string => '<tr>'.collect([$c->tipo, $c->numero, $c->tara, $c->seals_n1, $c->seals_n2, $c->seals_n3, $c->qty_pkgs, $c->gross_weight, $c->net_weight, $c->volume])
                ->map(fn ($value): string => '<td>'.e((string) $value).'</td>')
                ->implode('').'</tr>')
            ->implode('');

        return '<h3>Dados do BL</h3> '
            .'<p>Status: '.e((string) $this->status).'</p>'
            .'<p>Habilitar Impressão Original: '.($this->habImpressao ? 'Sim' : 'Não').'</p>'
            .'<p>No. Impressões Possíveis: '.e((string) $this->numImpressao).'</p>'
            .'<h3>Informacões de Contato</h3> '
            .'<p>Shipper/exporter: <br/>'.$text($this->shipper).'</p> '
            .'<p>Notify: <br/>'.$text($this->notify).'</p> '
            .'<p>Consignee: <br/>'.$text($this->consignee).'</p> '
            .'<p>Special Instructions:<br/>'.$text($this->special_instr).'</p> '
            .'<p>DMF Representations Agent at Destination:<br/>'.$text($this->representation).'</p> '
            .'<p>MBL Number: '.e((string) $this->mbl_number).'</p> '
            .'<h3>Informacões de Embarque</h3> '
            .'<p>Booking Nr: '.e((string) $booking).'</p> '
            .'<p>BL: '.e((string) $this->bl).'</p> '
            .'<p>Vessel - VG: '.e((string) $this->vessel).'</p> '
            .'<p>Port of Loading: '.e((string) $this->port_lading).'</p> '
            .'<p>Port of Transhipment: '.e((string) $this->port_trans).'</p> '
            .'<p>Port of Discharge: '.e((string) $this->port_disc).'</p> '
            .'<p>Place of Delivery: '.e((string) $this->place_delivery).'</p> '
            .'<p>DMF Ref.:'.e((string) $this->dmf_ref).'</p> '
            .'<h3>Containers</h3> '
            .'<table> <tr> <th>Type/Size</th> <th>No.</th> <th>Tara</th> <th colspan=3>Seals</th> <th>Qty</th> <th>Gross</th> <th>Net</th> <th>Volume</th> </tr> '.$rows.'</table> '
            .'<h3>Particulars Furnished By Shipper</h3> '
            .'<p>Mark & nos/container:<br/> '.$text($this->mark_nos).'</p> '
            .'<p>N of pkgs:<br/> '.$text($this->num_pkgs).'</p> '
            .'<p>Description of packages and goods:<br/> '.$text($this->desc_pkgs).'</p> '
            .'<p>Gross Weight (KGS): '.e((string) $this->gross_weight).'</p> '
            .'<p>Net Weight (KGS): '.e((string) $this->net_weight).'</p> '
            .'<p>Volume (m3): '.e((string) $this->volume).'</p> ';
    }
}

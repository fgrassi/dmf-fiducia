<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** invoice_impo / invoice_expo: o armador exige invoice do MBL para liberar a cobrança (InvoiceGate). */
class Armador extends Model
{
    protected $table = 'armador';

    protected $primaryKey = 'armador_id';

    public $timestamps = false;

    protected $guarded = [];

    public function embarques(): HasMany
    {
        return $this->hasMany(Embarque::class, 'armador_id', 'armador_id');
    }

    /**
     * Média de atraso global do legado (Armador::getMediaAtraso): por trecho, média do transit time efetivo − previsto;
     * a soma dos atrasos dos trechos é dividida pelo total de embarques (não ponderada, igual ao legado).
     */
    public function mediaAtraso(): float
    {
        $trechos = DB::table('vw_transit_time')
            ->selectRaw('count(*) as qtd, avg(tt_efetivo) - avg(tt_previsto) as atraso')
            ->where('armador_id', $this->getKey())
            ->whereNotNull('saida_prevista')
            ->whereNotNull('chegada_prevista')
            ->whereNotNull('saida_efetiva')
            ->whereNotNull('chegada_efetiva')
            ->groupBy('armador', 'origem', 'destino', 'pais_origem', 'pais_destino', 'trecho')
            ->get();

        $total = $trechos->sum('qtd');

        return $total ? $trechos->sum('atraso') / $total : 0.0;
    }

    public function ports(): HasMany
    {
        return $this->hasMany(CmnArmadorPorto::class, 'ref_armador', 'armador_id');
    }

    public function banks(): HasMany
    {
        return $this->hasMany(FinArmadorBanks::class, 'ref_armador', 'armador_id');
    }

    public function guaranteeDeposits(): HasMany
    {
        return $this->hasMany(DepositoGarantia::class, 'ref_armador', 'armador_id');
    }

    public function invoiceControls(): HasMany
    {
        return $this->hasMany(ControleInvoiceMaster::class, 'armador_id', 'armador_id');
    }
}

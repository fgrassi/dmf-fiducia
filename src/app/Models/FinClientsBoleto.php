<?php

namespace App\Models;

use App\Actions\Cobranca\Recalculation\ChargeRates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinClientsBoleto extends Model
{
    protected $table = 'fin_clients_boleto';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Pessoa::class, 'ref_client', 'pessoa_id');
    }

    /** clienteTemBoletoParaEmbarque: o cliente da reserva usa boleto no modal dela (IMPO/EXPO). */
    public static function usesBoleto(Embarque $embarque): bool
    {
        $suffix = ['i' => 'imp', 'e' => 'exp'][$embarque->impor_expor] ?? null;

        return $suffix && (int) self::of($embarque)?->{"boleto_{$suffix}"} === 1;
    }

    /** clienteTemBoletoAutomaticoParaEmbarque: usa boleto no modal e ele sai automaticamente com a IC. */
    public static function usesAutomaticBoleto(Embarque $embarque): bool
    {
        return self::usesBoleto($embarque)
            && (int) self::of($embarque)->{'boleto_automatico_'.($embarque->impor_expor === 'i' ? 'imp' : 'exp')} === 1;
    }

    private static function of(Embarque $embarque): ?self
    {
        $client = ChargeRates::clientId($embarque);

        return $client > 0 ? self::where('ref_client', $client)->first() : null;
    }
}

<?php

namespace App\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;

/**
 * Cobranca::montarSecoesEmailInstrucaoCobranca (legado): textos do e-mail da Instrução de Cobrança
 * (instrução de pagamento, aviso de boleto com prazo e "Late Payment Fee" do armador). O HTML fica na view.
 */
class InstructionEmailSections
{
    /** Cliente com horário de pagamento próprio. */
    private const SPECIAL_HOURS_CLIENT = 7146;

    /** Clientes que não recebem o aviso de boleto (e 3935 só na exportação). */
    private const NO_BOLETO_NOTICE = [1190];

    /** @return array{mode:string, special_hours:bool, bl:string, booking:string, import:bool, boleto:bool, late_payment:?array{carrier:string, html:string}} */
    public static function build(FinCharges $charge, bool $inException): array
    {
        $e = $charge->embarque;
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->value('bl') : null;
        $carrier = $e->armador_id ? DB::table('armador')->where('armador_id', $e->armador_id)->first(['nome', 'late_payment']) : null;

        return [
            // exceção (taxa negociada, antes do recálculo manual) troca a instrução pelo alerta
            'mode' => $inException ? 'exception' : ($e->impor_expor === 'i' ? 'import' : 'export'),
            'special_hours' => ChargeRates::clientId($e) === self::SPECIAL_HOURS_CLIENT,
            'bl' => (string) ($bl ?: $e->house_bl),
            'booking' => (string) $e->booking,
            'import' => $e->impor_expor === 'i',
            'boleto' => ! in_array((int) $e->cliente_id, self::NO_BOLETO_NOTICE, true)
                && $e->tipo_embarque === 'm'
                && ! ((int) $e->cliente_id === 3935 && $e->impor_expor === 'e')
                && DB::table('fin_clients_boleto')->where('ref_client', $e->cliente_id)->exists(),
            'late_payment' => filled($carrier?->late_payment) ? ['carrier' => (string) $carrier->nome, 'html' => (string) $carrier->late_payment] : null,
        ];
    }
}

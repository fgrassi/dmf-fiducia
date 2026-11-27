<?php

namespace App\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Documents\InstructionPdf;
use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\Embarque;
use App\Models\FinCharges;
use App\Models\FinChargesBoletoSicredi;
use App\Models\FinClientsBoleto;
use App\Models\FinEmailTexto;
use Illuminate\Support\Facades\DB;

/**
 * Cobranca::montarSecoesEmailInstrucaoCobranca (legado): textos do e-mail da Instrução de Cobrança (instrução de
 * pagamento, boleto e "Late Payment Fee" do armador), editáveis no cadastro "Textos de E-mail (IC)". O quadro fica na view.
 *
 * @phpstan-type Section array{title:string, html:string}
 */
class InstructionEmailSections
{
    /** Cliente com horário de pagamento próprio. */
    private const SPECIAL_HOURS_CLIENT = 7146;

    /** Clientes que não recebem a seção de boleto (e 3935 só na exportação). */
    private const NO_BOLETO_NOTICE = [1190];

    /** @return array{exception:bool, instruction:string, boleto:?Section, boleto_automatic:bool, late_payment:?Section} */
    public static function build(FinCharges $charge, bool $inException): array
    {
        $e = $charge->embarque;
        $import = $e->impor_expor === 'i';
        $clientId = ChargeRates::clientId($e);
        $client = DB::table('pessoa')->where('pessoa_id', $clientId)->first(['nome', 'razao_social']);
        $bl = $charge->ref_bl ? DB::table('bl')->where('bl_id', $charge->ref_bl)->value('bl') : null;
        $broker = self::destinationBroker($e);

        $values = [
            'bl' => e((string) ($bl ?: $e->house_bl)),
            'booking' => e((string) $e->booking),
            'invoice' => e((string) $e->invoice),
            'cliente' => e((string) ($client ? ($client->razao_social ?: $client->nome) : '')),
            'despachante_nome' => e((string) $broker?->nome),
            'despachante_endereco' => nl2br(e((string) $broker?->dados_contato)),
        ] + array_fill_keys(FinEmailTexto::PLACEHOLDERS, '');

        // exceção (taxa negociada, antes do recálculo manual) troca a instrução pelo alerta
        $instruction = match (true) {
            $inException => FinEmailTexto::body('IC_EMAIL_EXCECAO', $values),
            $import => FinEmailTexto::body('IC_EMAIL_IMPO', [
                'horario_comprovante' => FinEmailTexto::body($clientId === self::SPECIAL_HOURS_CLIENT ? 'IC_EMAIL_IMPO_HORARIO_CLIENTE_7146' : 'IC_EMAIL_IMPO_HORARIO_PADRAO', $values),
            ] + $values),
            default => FinEmailTexto::body('IC_EMAIL_EXPO', $values),
        };

        $automatic = FinClientsBoleto::usesAutomaticBoleto($e);

        return [
            'exception' => $inException,
            'instruction' => $instruction,
            'boleto' => self::boleto($charge, $values, $automatic),
            'boleto_automatic' => $automatic,
            'late_payment' => self::latePayment($e, $values),
        ];
    }

    /** Boleto automático: avisa que segue em anexo (ou que está sendo gerado). Senão, pede a confirmação dos valores. */
    private static function boleto(FinCharges $charge, array $values, bool $automatic): ?array
    {
        $e = $charge->embarque;

        if (in_array((int) $e->cliente_id, self::NO_BOLETO_NOTICE, true) || $e->tipo_embarque !== 'm' || ((int) $e->cliente_id === 3935 && $e->impor_expor === 'e')) {
            return null;
        }

        $suffix = $e->impor_expor === 'i' ? 'IMPO' : 'EXPO';

        if ($automatic) {
            $due = FinChargesBoletoSicredi::issuedFor($charge->id)?->data_vencimento?->format('d/m/Y') ?? '';
            $values = [
                'vencimento_boleto' => $due,
                'vencimento_boleto_frase' => $due !== '' ? ", com vencimento em <strong>{$due}</strong>" : '',
            ] + $values;
            $values = ['boleto_status' => FinEmailTexto::body(InstructionPdf::boletoFile($charge) ? 'IC_EMAIL_BOLETO_STATUS_ANEXO' : 'IC_EMAIL_BOLETO_STATUS_PENDENTE', $values)] + $values;

            return ['title' => FinEmailTexto::heading("IC_EMAIL_BOLETO_AUTO_{$suffix}", 'Boleto Bancário'), 'html' => FinEmailTexto::body("IC_EMAIL_BOLETO_AUTO_{$suffix}", $values)];
        }

        return DB::table('fin_clients_boleto')->where('ref_client', $e->cliente_id)->exists()
            ? ['title' => FinEmailTexto::heading("IC_EMAIL_BOLETO_MANUAL_{$suffix}", 'Informações Adicionais da Confirmação de Valores'), 'html' => FinEmailTexto::body("IC_EMAIL_BOLETO_MANUAL_{$suffix}", $values)]
            : null;
    }

    private static function latePayment(Embarque $e, array $values): ?array
    {
        $carrier = $e->armador_id ? DB::table('armador')->where('armador_id', $e->armador_id)->first(['nome', 'late_payment']) : null;

        if (blank($carrier?->late_payment)) {
            return null;
        }

        // o texto do armador é HTML do cadastro
        $values = ['armador' => e((string) $carrier->nome), 'late_payment' => (string) $carrier->late_payment] + $values;

        return [
            'title' => FinEmailTexto::heading('IC_EMAIL_LATE_PAYMENT_INTRO', 'Informativo “Late Payment Fee”'),
            'html' => FinEmailTexto::body('IC_EMAIL_LATE_PAYMENT_INTRO', $values).FinEmailTexto::body('IC_EMAIL_LATE_PAYMENT_FOOTER', $values),
        ];
    }

    /** resolverDespachantePadraoPortoDescarga (IMPO): o despachante padrão do porto de destino. */
    private static function destinationBroker(Embarque $e): ?object
    {
        return $e->impor_expor === 'i'
            ? DB::table('porto as p')->join('despachante as d', 'd.despachante_id', '=', 'p.despachante_id')
                ->where('p.porto_id', $e->porto_destino_id)->first(['d.nome', 'd.dados_contato'])
            : null;
    }
}

<?php

namespace App\Actions\Cobranca\Boleto;

use App\Models\FinChargesBoletoSicredi;
use DomainException;

/** SicrediBoletoService::baixar (legado): pedido de baixa do boleto emitido. A cobrança não muda. */
class CancelBoleto
{
    public function __construct(private SicrediApi $api) {}

    public function __invoke(FinChargesBoletoSicredi $boleto, ?int $userId): void
    {
        if ($boleto->status !== 'emitido') {
            throw new DomainException('Só é possível baixar boleto com status emitido.');
        }

        $ourNumber = preg_replace('/\D+/', '', (string) $boleto->nosso_numero);

        if ($ourNumber === '') {
            throw new DomainException('Boleto sem nosso número.');
        }

        $response = $this->api->cancel($ourNumber);
        $debug = json_encode(['nosso_numero' => $ourNumber] + $response);
        logger()->info("[SicrediBaixa] {$debug}");

        if (! $response['ok']) {
            $error = SicrediApi::errorMessage($response, 'Falha no pedido de baixa Sicredi.');
            $boleto->update(['erro_mensagem' => $error, 'response_json' => $debug]);

            throw new DomainException($error);
        }

        $boleto->update(['status' => 'baixado', 'erro_mensagem' => null, 'response_json' => $debug]);
        IssueBoleto::log($boleto->charge, "Pedido de baixa Sicredi (nosso número {$ourNumber}). Cobrança permanece inalterada.", $userId);
    }
}

<?php

namespace App\Actions\Cobranca\Boleto;

use App\Models\FinChargesBoletoSicredi;
use DomainException;
use Illuminate\Support\Str;

/**
 * SicrediBoletoService::consultarStatus (legado): atualiza o status do boleto pela situação na Sicredi, sem mudar a IC.
 * Título já em carteira e ainda sem PDF: baixa o arquivo; sem ele não vira "emitido", senão "Gerar" acha que acabou.
 */
class CheckBoletoStatus
{
    public function __construct(
        private SicrediApi $api,
        private IssueBoleto $issue,
    ) {}

    /** @return string a situação informada pela Sicredi, com o resultado do download do PDF quando houve */
    public function __invoke(FinChargesBoletoSicredi $boleto, ?int $userId): string
    {
        $ourNumber = preg_replace('/\D+/', '', (string) $boleto->nosso_numero);

        if ($ourNumber === '') {
            throw new DomainException('Boleto sem nosso número.');
        }

        $response = $this->api->show($ourNumber);
        $body = is_array($response['body']) ? (is_array($response['body'][0] ?? null) ? $response['body'][0] : $response['body']) : [];
        $situation = (string) ($body['situacao'] ?? $body['situação'] ?? '');
        $debug = json_encode(['nosso_numero' => $ourNumber, 'situacao' => $situation] + $response);
        logger()->info("[SicrediConsulta] {$debug}");

        if (! $response['ok']) {
            $error = SicrediApi::errorMessage($response, 'Falha na consulta do boleto Sicredi.');
            $boleto->update(['erro_mensagem' => $error, 'response_json' => $debug]);

            throw new DomainException($error);
        }

        $before = $boleto->status;
        $status = self::status($situation, $before);
        $label = $situation !== '' ? $situation : '(não informada)';

        if ($status === 'emitido' && ! $boleto->ref_file) {
            $boleto->update(['response_json' => $debug]);

            try {
                $this->issue->attachPdf($boleto->charge, $boleto);
            } catch (DomainException $e) {
                return "{$label}. {$e->getMessage()}";
            }

            return "{$label}. PDF do boleto baixado.";
        }

        $boleto->update(['status' => $status, 'erro_mensagem' => null, 'response_json' => $debug]);

        if ($status !== $before) {
            IssueBoleto::log($boleto->charge, "Consulta Sicredi: situação \"{$situation}\" → status boleto \"{$status}\" (nosso número {$ourNumber}). IC inalterada.", $userId);
        }

        return $label;
    }

    /** mapearSituacaoParaStatus: situação desconhecida mantém o status. */
    public static function status(string $situation, string $current): string
    {
        $s = Str::squish(mb_strtoupper($situation));

        return match (true) {
            str_starts_with($s, 'LIQUIDADO') => 'liquidado',
            str_starts_with($s, 'BAIXADO') => 'baixado',
            $s === 'REJEITADO' => 'erro',
            in_array($s, ['EM CARTEIRA', 'EM CARTEIRA PIX', 'VENCIDO'], true), str_starts_with($s, 'AGUARDANDO'),
            Str::contains($s, ['EM CARTORIO', 'NEGATIVADO', 'PROTESTADO']) => 'emitido',
            default => $current,
        };
    }
}

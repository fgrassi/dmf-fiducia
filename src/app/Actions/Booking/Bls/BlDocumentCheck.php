<?php

namespace App\Actions\Booking\Bls;

use App\Actions\Cobranca\Nfse\ServiceTaker;

/** avaliarDocumentoTextoBl (legado): o shipper (expo) ou consignee (impo) do BL traz um CNPJ/CPF válido? */
class BlDocumentCheck
{
    public const OK = 'ok';

    /** @return array{status: string, message: string} */
    public function __invoke(?string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        if ($candidate = $this->cnpjCandidate($text)) {
            return strlen($candidate['digits']) === 14 && ServiceTaker::validCnpj($candidate['digits'])
                ? ['status' => self::OK, 'message' => '']
                : ['status' => 'invalid', 'message' => 'CNPJ inválido: '.$candidate['raw']];
        }

        return strlen((string) ServiceTaker::documentIn($text)) === 11
            ? ['status' => self::OK, 'message' => '']
            : ['status' => 'missing', 'message' => 'Não há CNPJ informado.'];
    }

    /** achaCandidatoCnpjNoTextoBl: o que parece um CNPJ no texto, sem validar os dígitos. */
    private function cnpjCandidate(string $text): ?array
    {
        if (preg_match('/\b(C\.?N\.?P\.?J|C\.?N\.?J\.?P)\b\s*[:.\-]?\s*([0-9][0-9\.\-\/\s]{0,30})/i', $text, $found)) {
            return ['raw' => trim($found[2]), 'digits' => substr(preg_replace('/\D+/', '', $found[2]), 0, 14)];
        }

        if (preg_match('/\b\d{2}\.+\d{3}\.+\d{3}\s?\/\s?\d{4}\s?\-\s?\d{2}\b/', $text, $found)) {
            return ['raw' => $found[0], 'digits' => preg_replace('/\D+/', '', $found[0])];
        }

        return null;
    }
}

<?php

namespace App\Actions\Contabilidade;

/** Número e data de emissão da NFS-e, como o legado os mostra nos relatórios de contabilidade. */
class NfseInvoice
{
    /** Número da NFS-e nacional: 13 dígitos a partir da posição 24 da chave de 50 dígitos; senão o número da cobrança; senão a chave. */
    public static function number(mixed $key, mixed $fallback): string
    {
        $digits = preg_replace('/\D/', '', (string) $key);

        if (strlen($digits) === 50 && ltrim(substr($digits, 23, 13), '0') !== '') {
            return ltrim(substr($digits, 23, 13), '0');
        }

        return filled(trim((string) $fallback)) ? trim((string) $fallback) : trim((string) $key);
    }

    /** Emissão do payload (`data_emissao`), senão a criação do registro. */
    public static function issueDate(mixed $payloadJson, mixed $createdAt): ?string
    {
        $payload = json_decode((string) $payloadJson, true);

        return (is_array($payload) ? self::date($payload['data_emissao'] ?? null) : null) ?? self::date($createdAt);
    }

    /** Aceita Y-m-d (com hora) ou d/m/Y; zero-date e vazio viram null. */
    public static function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return match (true) {
            str_starts_with($value, '0000-00-00') => null,
            (bool) preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) => $m[1],
            (bool) preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $value, $m) => "{$m[3]}-{$m[2]}-{$m[1]}",
            default => null,
        };
    }
}

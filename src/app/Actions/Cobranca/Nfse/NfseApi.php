<?php

namespace App\Actions\Cobranca\Nfse;

use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * NfseClient (legado): API de NFS-e (Laravel, Bearer token). URL e token vêm do config_nfse id 1, como no legado;
 * NFSE_API_URL/NFSE_API_TOKEN no .env sobrescrevem (dev/homologação não podem falar com a produção).
 *
 * @phpstan-type Result array{ok:bool, http_code:int, body:mixed, raw:string, error:?string}
 */
class NfseApi
{
    private const TIMEOUT = 30;

    /** @return Result */
    public function issue(array $payload): array
    {
        return $this->request('POST', '/nfse', $payload);
    }

    /** @return Result */
    public function show(string $uuid): array
    {
        return $this->request('GET', '/nfse/'.rawurlencode($uuid));
    }

    /** @return Result */
    public function xml(string $uuid): array
    {
        return $this->request('GET', '/nfse/'.rawurlencode($uuid).'/xml', accept: 'application/xml, application/json', json: false);
    }

    /** @return Result */
    public function pdf(string $uuid): array
    {
        return $this->request('GET', '/nfse/'.rawurlencode($uuid).'/pdf', accept: 'application/pdf, application/json', json: false);
    }

    /** Cancelamento assíncrono (202 = enfileirado). @param array{c_motivo:string, x_motivo?:string} $payload @return Result */
    public function cancel(string $uuid, array $payload): array
    {
        return $this->request('POST', '/nfse/'.rawurlencode($uuid).'/cancelar', $payload);
    }

    /** Substituição (nova DPS com grupo subst). 202 = enfileirada; 200 = idempotente. @return Result */
    public function substitute(string $uuid, array $payload): array
    {
        return $this->request('POST', '/nfse/'.rawurlencode($uuid).'/substituir', $payload);
    }

    /** ConfigNfseTable::getAtual + override do .env. @return array{url:string, token:string} */
    public static function credentials(): array
    {
        $config = DB::table('config_nfse')->where('config_nfse_id', 1)->first(['api_url', 'api_token']);

        return [
            'url' => rtrim((string) (config('services.nfse.url') ?: $config?->api_url), '/'),
            'token' => (string) (config('services.nfse.token') ?: $config?->api_token),
        ];
    }

    public static function configured(): bool
    {
        ['url' => $url, 'token' => $token] = self::credentials();

        return $url !== '' && $token !== '';
    }

    /** @return Result */
    private function request(string $method, string $path, ?array $payload = null, string $accept = 'application/json', bool $json = true): array
    {
        if (! self::configured()) {
            throw new DomainException('API de NFS-e não configurada (config_nfse).');
        }

        ['url' => $url, 'token' => $token] = self::credentials();

        try {
            /** @var Response $response */
            $response = Http::withToken($token)
                ->accept($accept)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(10)
                ->send($method, $url.$path, $payload === null ? [] : ['json' => $payload]);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'http_code' => 0, 'body' => null, 'raw' => '', 'error' => $e->getMessage()];
        }

        $raw = $response->body();

        return [
            'ok' => $response->successful(),
            'http_code' => $response->status(),
            'body' => $raw === '' ? null : ($json ? ($response->json() ?? $raw) : $raw),
            'raw' => $raw,
            'error' => null,
        ];
    }
}

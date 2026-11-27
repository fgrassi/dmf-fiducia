<?php

namespace App\Actions\Cobranca\Boleto;

use Closure;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SicrediClient (legado): API de Cobrança do Sicredi. Tudo vem do .env (services.sicredi); sem URL e credenciais nada
 * sai da aplicação. O token fica em cache até perto de expirar (sem o refresh_token do legado: pede um novo).
 *
 * A contratação do webhook (criarContratoWebhook) não é chamada por nenhuma tela do legado e não foi portada.
 *
 * @phpstan-type Result array{ok:bool, http_code:int, body:mixed, raw:string, error:?string}
 */
class SicrediApi
{
    private const TIMEOUT = 30;

    private const BOLETOS = 'cobranca/boleto/v1/boletos';

    public static function configured(): bool
    {
        return collect(['url', 'x_api_key', 'username', 'password', 'cooperativa', 'posto', 'beneficiary'])
            ->every(fn (string $key) => filled(config("services.sicredi.{$key}")));
    }

    public static function beneficiary(): string
    {
        return (string) config('services.sicredi.beneficiary');
    }

    /** @return Result */
    public function register(array $payload): array
    {
        return $this->authorized(fn (PendingRequest $http) => $http->withHeaders($this->office())->post(self::BOLETOS, $payload));
    }

    /** @return Result */
    public function show(string $ourNumber): array
    {
        return $this->authorized(fn (PendingRequest $http) => $http->withHeaders($this->office())
            ->get(self::BOLETOS, ['codigoBeneficiario' => self::beneficiary(), 'nossoNumero' => $ourNumber]));
    }

    /** Pedido de baixa (manual Sicredi 7.4). @return Result */
    public function cancel(string $ourNumber): array
    {
        return $this->authorized(fn (PendingRequest $http) => $http->withHeaders($this->office() + ['codigoBeneficiario' => self::beneficiary()])
            ->patch(self::BOLETOS."/{$ourNumber}/baixa", []));
    }

    /** @return Result */
    public function pdf(string $digitableLine): array
    {
        return $this->authorized(fn (PendingRequest $http) => $http->withHeaders($this->office())->accept('application/pdf, application/json, application/octet-stream')
            ->get(self::BOLETOS.'/pdf', ['linhaDigitavel' => $digitableLine]));
    }

    /** mensagemErroApi: a mensagem devolvida pela Sicredi, ou $fallback. @param Result $response */
    public static function errorMessage(array $response, string $fallback): string
    {
        if ($response['error']) {
            return "{$fallback} ({$response['error']})";
        }

        $body = $response['body'];

        if (is_array($body)) {
            foreach (['Mensagem', 'mensagem', 'message', 'error', 'error_description'] as $key) {
                if (is_string($body[$key] ?? null) && $body[$key] !== '') {
                    return $body[$key];
                }
            }

            $errors = collect((array) ($body['erros'] ?? []))->map(fn ($e) => is_string($e) ? $e : ($e['mensagem'] ?? null))->filter();

            if ($errors->isNotEmpty()) {
                return $errors->implode('; ');
            }
        } elseif (is_string($body) && $body !== '' && strlen($body) < 500) {
            return $body;
        }

        return $fallback.($response['http_code'] ? " (HTTP {$response['http_code']})" : '');
    }

    /** @param Closure(PendingRequest):Response $send @return Result */
    private function authorized(Closure $send): array
    {
        if (! self::configured()) {
            throw new DomainException('API do Sicredi não configurada (SICREDI_* no .env).');
        }

        $token = Cache::get('sicredi-token');

        if (! $token) {
            $auth = $this->result(fn () => $this->http()->withHeaders(['context' => 'COBRANCA'])->asForm()->post('auth/openapi/token', [
                'grant_type' => 'password',
                'username' => config('services.sicredi.username'),
                'password' => config('services.sicredi.password'),
                'scope' => 'cobranca',
            ]));
            $token = $auth['ok'] ? ($auth['body']['access_token'] ?? null) : null;

            if (! $token) {
                return ['ok' => false] + $auth;
            }

            Cache::put('sicredi-token', $token, max(1, (int) ($auth['body']['expires_in'] ?? 300) - 15));
        }

        return $this->result(fn () => $send($this->http()->withToken($token)));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.sicredi.url'), '/').'/')
            ->timeout(self::TIMEOUT)
            ->connectTimeout(10)
            ->withHeaders(['x-api-key' => config('services.sicredi.x_api_key')])
            ->acceptJson();
    }

    private function office(): array
    {
        return ['cooperativa' => config('services.sicredi.cooperativa'), 'posto' => config('services.sicredi.posto')];
    }

    /** @param Closure():Response $send @return Result */
    private function result(Closure $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            return ['ok' => false, 'http_code' => 0, 'body' => null, 'raw' => '', 'error' => $e->getMessage()];
        }

        return ['ok' => $response->successful(), 'http_code' => $response->status(), 'body' => $response->json() ?? $response->body(), 'raw' => $response->body(), 'error' => null];
    }
}

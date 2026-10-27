<?php

namespace App\Actions\Cobranca\Nfse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Endereço do tomador (BrasilApiCepClient / IbgeClient / OpenCnpjClient do legado): CEP pelo ViaCEP (já traz o código IBGE),
 * município IBGE pelo nome + UF e o endereço do CNPJ do BL pela OpenCNPJ.
 *
 * ponytail: só ViaCEP (sem o fallback BrasilAPI) e sem a busca de CEP por logradouro (buscarCepEndereco); somar se o ViaCEP falhar com frequência.
 */
class AddressLookup
{
    private const TIMEOUT = 8;

    /** validarCep: null = não encontrado/indisponível. @return ?array{cep:string, logradouro:string, bairro:string, municipio:string, uf:string, codigo_municipio:string} */
    public function cep(string $cep): ?array
    {
        $digits = preg_replace('/\D+/', '', $cep);

        if (strlen($digits) !== 8) {
            return null;
        }

        try {
            $body = Http::timeout(self::TIMEOUT)->acceptJson()->get("https://viacep.com.br/ws/{$digits}/json/")->json();
        } catch (ConnectionException) {
            return null;
        }

        if (! is_array($body) || ! empty($body['erro']) || empty($body['cep'])) {
            return null;
        }

        return [
            'cep' => $digits,
            'logradouro' => (string) ($body['logradouro'] ?? ''),
            'bairro' => (string) ($body['bairro'] ?? ''),
            'municipio' => (string) ($body['localidade'] ?? ''),
            'uf' => strtoupper((string) ($body['uf'] ?? '')),
            'codigo_municipio' => preg_replace('/\D+/', '', (string) ($body['ibge'] ?? '')),
        ];
    }

    /** IbgeClient::resolverMunicipio: código IBGE pelo nome exato (sem acentos) do município na UF. Lista cacheada por 30 dias. */
    public function ibgeCode(string $city, string $uf): ?string
    {
        $uf = strtoupper(trim($uf));
        $key = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($s)));

        if ($key($city) === '' || ! preg_match('/^[A-Z]{2}$/', $uf)) {
            return null;
        }

        $found = collect($this->ibgeCities($uf))->first(fn ($c) => $key((string) ($c['nome'] ?? '')) === $key($city));

        return $found ? (string) $found['id'] : null;
    }

    /** IbgeClient::buscarMunicipios: municípios cujo nome (sem acentos) contém o termo; UF opcional. @return list<array{id:string, nome:string, uf:string}> */
    public function searchCities(string $term, string $uf = '', int $limit = 25): array
    {
        $uf = strtoupper(trim($uf));
        $key = fn (string $s) => preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($s)));

        if (mb_strlen(trim($term)) < 2) {
            return [];
        }

        return collect($this->ibgeCities(preg_match('/^[A-Z]{2}$/', $uf) ? $uf : ''))
            ->filter(fn ($c) => isset($c['id'], $c['nome']) && str_contains($key($c['nome']), $key($term)))
            ->take($limit)
            ->map(fn ($c) => [
                'id' => (string) $c['id'],
                'nome' => (string) $c['nome'],
                'uf' => (string) (data_get($c, 'microrregiao.mesorregiao.UF.sigla') ?? data_get($c, 'regiao-imediata.regiao-intermediaria.UF.sigla') ?? $uf),
            ])
            ->values()
            ->all();
    }

    /** IbgeClient::municipioPorCodigo. @return ?array{id:string, nome:string, uf:string} */
    public function city(string $code): ?array
    {
        $code = preg_replace('/\D+/', '', $code);

        if (strlen($code) !== 7) {
            return null;
        }

        try {
            $c = Http::timeout(self::TIMEOUT)->get("https://servicodados.ibge.gov.br/api/v1/localidades/municipios/{$code}")->json();
        } catch (ConnectionException) {
            return null;
        }

        return empty($c['nome']) ? null : [
            'id' => (string) $c['id'],
            'nome' => trim((string) $c['nome']),
            'uf' => strtoupper((string) (data_get($c, 'microrregiao.mesorregiao.UF.sigla') ?? data_get($c, 'regiao-imediata.regiao-intermediaria.UF.sigla') ?? '')),
        ];
    }

    /** Lista de municípios do IBGE (da UF ou do país), cacheada por 30 dias. */
    private function ibgeCities(string $uf): array
    {
        $url = $uf !== ''
            ? "https://servicodados.ibge.gov.br/api/v1/localidades/estados/{$uf}/municipios"
            : 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';

        $cacheKey = 'ibge-municipios-'.($uf ?: 'all');

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $cities = Http::timeout(self::TIMEOUT)->get($url)->json() ?: [];
        } catch (ConnectionException) {
            return [];
        }

        // Só cacheia resposta válida: uma falha do IBGE não pode deixar a busca vazia por 30 dias.
        if (is_array($cities) && $cities !== []) {
            Cache::put($cacheKey, $cities, now()->addDays(30));
        }

        return is_array($cities) ? $cities : [];
    }

    /**
     * buildEnderecoTomadorFromOpenCnpj: endereço do CNPJ (o IBGE vem da API ou é resolvido pelo nome do município).
     *
     * @return array{endereco:array<string, string>, erro:?string}
     */
    public function openCnpj(string $cnpj): array
    {
        $empty = ['cep' => '', 'logradouro' => '', 'numero' => '', 'bairro' => '', 'codigo_municipio' => '', 'uf' => ''];
        $cnpj = ServiceTaker::normalizeCnpj($cnpj);

        if (! ServiceTaker::validCnpj($cnpj)) {
            return ['endereco' => $empty, 'erro' => 'CNPJ do tomador inválido para consulta na OpenCNPJ.'];
        }

        try {
            $response = Http::timeout(self::TIMEOUT)->connectTimeout(3)->acceptJson()->get("https://api.opencnpj.org/{$cnpj}");
        } catch (ConnectionException) {
            return ['endereco' => $empty, 'erro' => 'Não foi possível consultar o CNPJ na OpenCNPJ. Tente novamente em instantes.'];
        }

        $data = $response->json();
        $error = match (true) {
            in_array($response->status(), [400, 404], true) => 'CNPJ não encontrado na OpenCNPJ.',
            $response->status() === 429 => 'O limite de consultas da OpenCNPJ foi atingido. Aguarde um momento e tente novamente.',
            ! $response->successful() => 'A consulta de CNPJ está temporariamente indisponível.',
            ! is_array($data) || empty($data['cnpj']) => 'A consulta de CNPJ retornou dados em um formato inesperado.',
            default => null,
        };

        if ($error) {
            return ['endereco' => $empty, 'erro' => $error];
        }

        $uf = strtoupper(trim((string) ($data['uf'] ?? '')));
        $city = trim((string) (($data['municipio'] ?? '') ?: ($data['nome_cidade_exterior'] ?? '')));
        $code = preg_replace('/\D+/', '', (string) (($data['codigo_municipio_ibge'] ?? '') ?: ($data['codigo_municipio'] ?? '')));

        if (strlen($code) !== 7 && $city !== '') {
            $code = (string) $this->ibgeCode($city, $uf);
        }

        return [
            'endereco' => [
                'cep' => preg_replace('/\D+/', '', (string) ($data['cep'] ?? '')),
                'logradouro' => trim(($data['tipo_logradouro'] ?? '').' '.($data['logradouro'] ?? '')),
                'numero' => trim((string) ($data['numero'] ?? '')),
                'bairro' => trim((string) ($data['bairro'] ?? '')),
                'codigo_municipio' => strlen($code) === 7 ? $code : '',
                'uf' => $uf,
            ],
            'erro' => null,
        ];
    }
}

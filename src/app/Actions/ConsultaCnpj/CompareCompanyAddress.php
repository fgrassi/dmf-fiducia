<?php

namespace App\Actions\ConsultaCnpj;

use App\Models\Pessoa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endereço da OpenCNPJ contra o cadastro da empresa. O cadastro guarda rua, número e complemento num texto só (pessoa.endereco),
 * então esses três conferem por "está contido" (sem acento, caixa ou pontuação); CEP, bairro e cidade/UF conferem por igualdade.
 */
class CompareCompanyAddress
{
    /**
     * Só os campos que divergem, com o valor do cadastro (vazio vira "não informado").
     *
     * @param  array<string, mixed>  $company  resposta da OpenCNPJ
     * @return array<string, string> chaves: cep, logradouro, numero, complemento, bairro, municipio
     */
    public function __invoke(array $company, Pessoa $registered): array
    {
        $street = $this->key($company['logradouro'] ?? '');
        $number = trim((string) ($company['numero'] ?? ''));
        $complement = $this->key($company['complemento'] ?? '');
        $address = $this->key($registered->endereco);
        $city = DB::table('cidades')
            ->join('estados', 'estados.estados_id', '=', 'cidades.estados_id')
            ->where('cidades.cidades_id', $registered->cidades_id)
            ->first(['cidades.nome', 'estados.uf']);

        $differences = [
            'cep' => $this->digits($company['cep'] ?? '') !== '' && $this->digits($company['cep']) !== $this->digits($registered->cep),
            'logradouro' => $street !== '' && ! str_contains($address, $street),
            'numero' => preg_match('/^\d+$/', $number) && ! preg_match('/(?<!\d)'.$number.'(?!\d)/', (string) $registered->endereco),
            'complemento' => $complement !== '' && ! str_contains($address, $complement),
            'bairro' => $this->key($company['bairro'] ?? '') !== '' && $this->key($company['bairro']) !== $this->key($registered->bairro),
            'municipio' => $this->key($company['municipio'] ?? '') !== '' && (
                ! $city
                || $this->key($company['municipio']) !== $this->key($city->nome)
                || $this->key($company['uf'] ?? '') !== $this->key($city->uf)
            ),
        ];

        $shown = [
            'cep' => $this->mask($this->digits($registered->cep)),
            'logradouro' => $registered->endereco,
            'numero' => $registered->endereco,
            'complemento' => $registered->endereco,
            'bairro' => $registered->bairro,
            'municipio' => $city ? trim("{$city->nome} - {$city->uf}", ' -') : null,
        ];

        return collect($differences)
            ->filter()
            ->mapWithKeys(fn ($_, $field) => [$field => filled($shown[$field]) ? (string) $shown[$field] : 'não informado'])
            ->all();
    }

    private function key(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii((string) $value)));
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    private function mask(string $cep): string
    {
        return preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $cep);
    }
}

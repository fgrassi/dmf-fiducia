<?php

namespace App\Actions\Companies;

use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro da empresa pela OpenCNPJ: devolve cada dado oferecido ao usuário (rótulo para o modal + campos do formulário que ele preenche).
 */
class LookupCompanyCnpj
{
    public function __construct(private AddressLookup $lookup) {}

    /**
     * @return array{erro: ?string, fields: array<string, array{label: string, values: array<string, mixed>}>}
     */
    public function __invoke(string $cnpj): array
    {
        if (! ServiceTaker::validCnpj($cnpj)) {
            return ['erro' => 'Informe um CNPJ válido com 14 caracteres.', 'fields' => []];
        }

        ['data' => $data, 'erro' => $error] = $this->lookup->openCnpjData($cnpj);

        if ($error) {
            return ['erro' => $error, 'fields' => []];
        }

        $address = $this->lookup->openCnpj($cnpj);
        $phone = $data['telefones'][0] ?? null;
        $phone = $phone ? trim(($phone['ddd'] ?? '').' '.($phone['numero'] ?? '')) : '';
        $street = trim(($address['endereco']['logradouro'] ?? '').', '.($address['endereco']['numero'] ?? ''), ' ,');
        $street = trim($street.' '.(filled($data['complemento'] ?? null) ? '- '.trim($data['complemento']) : ''));
        $cep = preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $address['endereco']['cep']);

        $fields = [
            'razao_social' => ['Razão Social', $data['razao_social'] ?? '', ['razao_social' => $data['razao_social'] ?? '']],
            'email' => ['Email', $data['email'] ?? '', ['email' => $data['email'] ?? '']],
            'fone1' => ['Telefone', $phone, ['fone1' => $phone]],
            'endereco' => ['Endereço', $street, ['endereco' => $street]],
            'bairro' => ['Bairro', $address['endereco']['bairro'], ['bairro' => $address['endereco']['bairro']]],
            'cep' => ['CEP', $cep, ['cep' => $cep]],
            'cidade' => $this->city($address),
        ];

        return [
            'erro' => null,
            'fields' => collect($fields)
                ->filter(fn (array $field) => filled($field[1]))
                ->map(fn (array $field) => ['label' => "{$field[0]}: {$field[1]}", 'values' => $field[2]])
                ->all(),
        ];
    }

    /**
     * País, estado e cidade do cadastro (quando existem nas tabelas) e o código IBGE.
     *
     * @param  array<string, mixed>  $address  retorno de AddressLookup::openCnpj
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function city(array $address): array
    {
        $uf = $address['endereco']['uf'];
        $code = $address['endereco']['codigo_municipio'];
        $values = array_filter(['codigo_municipio_ibge' => $code]);

        $country = DB::table('paises')->where('nome', $address['pais'])->value('paises_id');
        $state = $country ? DB::table('estados')->where('paises_id', $country)->where('uf', $uf)->value('estados_id') : null;
        $cityId = $state ? DB::table('cidades')->where('estados_id', $state)->where('nome', $address['municipio'])->value('cidades_id') : null;

        if ($cityId) {
            $values += ['paises_id' => $country, 'estados_id' => $state, 'cidades_id' => $cityId];
        }

        return ['Cidade', trim("{$address['municipio']} - {$uf}", ' -').($code ? " (IBGE {$code})" : ''), $values];
    }
}

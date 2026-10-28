<?php

namespace App\Actions\Cobranca\Nfse;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * verificaTomador / listarTomadoresComPendencia (legado): tomadores dos BLs (shipper na exportação por saída prevista, consignee
 * na importação por chegada prevista, reservas não canceladas) cujo cadastro não fecha o endereço que a DPS exige.
 * Com $suggest, completa o que falta com a OpenCNPJ (a tela do financeiro); o aviso ao TI não consulta.
 *
 * @phpstan-type Row array{id:int|string, nome:string, endereco:string, numero:string, bairro:string, cep:string, cidade:string, uf:string, ibge:string, pais:string, cnpj:string, pendencias:list<string>}
 */
class PendingTakers
{
    public function __construct(private ServiceTaker $taker, private AddressLookup $lookup) {}

    /** @return list<Row> */
    public function __invoke(string $from, string $to, bool $suggest = false): array
    {
        return $this->documents($from, $to)
            ->map(fn (string $doc) => $this->row($doc, $suggest))
            ->filter()
            ->values()
            ->all();
    }

    /** @return Collection<int, string> CNPJ/CPF únicos dos BLs do período */
    private function documents(string $from, string $to): Collection
    {
        $texts = fn (string $mode, string $date, string $column) => DB::table('bl')
            ->join('embarque as e', 'e.embarque_id', '=', 'bl.embarque_id')
            ->where('e.finalizado', '<>', 'c')
            ->where('e.impor_expor', $mode)
            ->whereBetween("e.{$date}", [$from, $to])
            ->pluck("bl.{$column}");

        return $texts('e', 'saida_prevista', 'shipper')
            ->merge($texts('i', 'chegada_prevista', 'consignee'))
            ->map(fn ($text) => ServiceTaker::documentIn((string) $text))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return ?Row null quando o cadastro está completo */
    private function row(string $doc, bool $suggest): ?array
    {
        $person = DB::table('pessoa')
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '')) = ?", [$doc])
            ->first();
        $api = $suggest ? $this->lookup->openCnpj($doc) : null;

        if (! $person) {
            return [
                'id' => '',
                'nome' => $api['razao_social'] ?? '',
                'endereco' => $api['endereco']['logradouro'] ?? '',
                'numero' => $api['endereco']['numero'] ?? '',
                'bairro' => $api['endereco']['bairro'] ?? '',
                'cep' => $api['endereco']['cep'] ?? '',
                'cidade' => $api['municipio'] ?? '',
                'uf' => $api['endereco']['uf'] ?? '',
                'ibge' => $api['endereco']['codigo_municipio'] ?? '',
                'pais' => $api['pais'] ?? '',
                'cnpj' => ServiceTaker::formatDocument($doc),
                'pendencias' => ['Empresa sem cadastro de pessoa.'],
            ];
        }

        $address = $this->taker->personAddress($person);
        $issues = ServiceTaker::addressIssues($address);
        $city = (string) DB::table('cidades')->where('cidades_id', $person->cidades_id)->value('nome');
        $pendencias = array_values($issues);

        if (trim($city) === '') {
            $pendencias[] = 'Cidade do tomador não informada.';
        }

        if ($pendencias === []) {
            return null;
        }

        // sugestão da OpenCNPJ só no campo que está pendente
        $suggestion = fn (string $key, string $current) => isset($issues[$key]) && filled($api['endereco'][$key] ?? null) ? $api['endereco'][$key] : $current;

        return [
            'id' => $person->pessoa_id,
            'nome' => (string) $person->nome,
            'endereco' => $suggestion('logradouro', $address['logradouro']),
            'numero' => $suggestion('numero', $address['numero']),
            'bairro' => $suggestion('bairro', (string) $person->bairro),
            'cep' => $suggestion('cep', (string) $person->cep),
            'cidade' => trim($city) === '' ? (string) ($api['municipio'] ?? '') : $city,
            'uf' => $suggestion('uf', (string) DB::table('cidades as c')->join('estados as e', 'e.estados_id', '=', 'c.estados_id')->where('c.cidades_id', $person->cidades_id)->value('e.uf')),
            'ibge' => $suggestion('codigo_municipio', (string) $person->codigo_municipio_ibge),
            'pais' => (string) DB::table('paises')->where('paises_id', $person->paises_id)->value('nome'),
            'cnpj' => (string) $person->cnpj !== '' ? (string) $person->cnpj : ServiceTaker::formatDocument($doc),
            'pendencias' => $pendencias,
        ];
    }
}

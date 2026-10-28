<?php

namespace App\Actions\Cobranca\Nfse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * financeiro/atualizaTomador (legado): grava na pessoa o endereço corrigido na tela de tomadores (tudo em maiúsculas), cria a pessoa
 * dos CNPJs sem cadastro e resolve cidade (criando a que faltar, pelo nome oficial do IBGE), sigla do estado e país.
 *
 * ponytail: sem a busca de município "parecido" nem a criação de estado do legado (as 27 UFs já existem); somar se aparecer aviso de cidade/UF não encontrada.
 *
 * @phpstan-import-type Row from PendingTakers
 */
class UpdateTakers
{
    /** @var array<string, array<string, int>> UF → chave do nome da cidade → cidades_id */
    private array $cities = [];

    /** @var ?array<string, int> */
    private ?array $countries = null;

    public function __construct(private ServiceTaker $taker, private AddressLookup $lookup) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{ok:bool, message:string, errors:list<string>}
     */
    public function __invoke(array $rows, ?int $userId): array
    {
        $updated = $created = 0;
        $errors = [];

        foreach ($rows as $row) {
            $cnpj = ServiceTaker::normalizeCnpj((string) ($row['cnpj'] ?? ''));
            $label = ServiceTaker::formatDocument($cnpj);

            if (! ServiceTaker::validCnpj($cnpj)) {
                $errors[] = 'CNPJ inválido: '.($row['cnpj'] ?? '');

                continue;
            }

            $name = $this->upper($row['nome'] ?? '');

            if ($name === '') {
                $errors[] = "CNPJ {$label} sem nome.";

                continue;
            }

            $person = $this->findPerson((int) ($row['id'] ?? 0), $cnpj);
            $new = ! $person;
            $values = ['nome' => $this->cut($name, 255), 'razao_social' => $this->cut($name, 255), 'cnpj' => $label];

            $parts = $this->taker->splitNumber(['logradouro' => $this->upper($row['endereco'] ?? ''), 'numero' => '']);
            $number = ServiceTaker::canonicalNumber((string) ($row['numero'] ?? '')) ?: $parts['numero'];
            $street = trim($parts['logradouro'].($number !== '' ? ", {$number}" : ''), " \t,");

            if ($street !== '') {
                $values['endereco'] = $this->cut($street, 255);
            }

            foreach (['bairro' => 255, 'cep' => 15] as $column => $limit) {
                if (($text = $this->cut($this->upper($row[$column] ?? ''), $limit)) !== '') {
                    $values[$column] = $text;
                }
            }

            $ibge = preg_replace('/\D+/', '', (string) ($row['ibge'] ?? ''));
            $warnings = [];

            if (strlen($ibge) === 7) {
                $values['codigo_municipio_ibge'] = $ibge;
            }

            $cityName = $this->upper($row['cidade'] ?? '');
            $uf = $this->upper($row['uf'] ?? '');

            if ($cityName !== '' || strlen($ibge) === 7) {
                if ($city = $this->resolveCity($cityName, $uf, $ibge)) {
                    $values['cidades_id'] = $city['id'];
                    $this->ensureStateAcronym($city['id'], $city['uf'] ?: $uf);
                    $values['codigo_municipio_ibge'] = $city['ibge'] ?: ($values['codigo_municipio_ibge'] ?? null);
                } else {
                    $warnings[] = "cidade {$cityName}".($uf !== '' ? "/{$uf}" : '').' não encontrada';
                }
            }

            if (($country = $this->upper($row['pais'] ?? '')) !== '') {
                if ($countryId = $this->countryId($country)) {
                    $values['paises_id'] = $countryId;
                } else {
                    $warnings[] = "país {$country} não encontrado";
                }
            }

            try {
                if ($new) {
                    DB::table('pessoa')->insert(array_filter($values, fn ($v) => $v !== null) + [
                        'dteCadastro' => now(),
                        'histCompleto' => 'false',
                        'created_by' => $userId,
                    ]);
                } else {
                    DB::table('pessoa')->where('pessoa_id', $person->pessoa_id)->update(array_filter($values, fn ($v) => $v !== null) + ['dteMovimentacao' => now()]);
                }
            } catch (Throwable $e) {
                report($e);
                $errors[] = "CNPJ {$label} não foi gravado.";

                continue;
            }

            $new ? $created++ : $updated++;

            if ($warnings !== []) {
                $errors[] = "CNPJ {$label}: ".implode('; ', $warnings).'.';
            }
        }

        return [
            'ok' => $updated + $created > 0,
            'message' => trim("{$updated} cadastro(s) atualizado(s) e {$created} criado(s). ".implode(' ', $errors)),
            'errors' => $errors,
        ];
    }

    private function findPerson(int $id, string $cnpj): ?object
    {
        return ($id > 0 ? DB::table('pessoa')->where('pessoa_id', $id)->first() : null)
            ?? DB::table('pessoa')
                ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '')) = ?", [$cnpj])
                ->first();
    }

    /** resolverOuCriarCidade. @return ?array{id:int, uf:string, ibge:string} */
    private function resolveCity(string $name, string $uf, string $ibge): ?array
    {
        if ($id = $this->cityId($name, $uf)) {
            return ['id' => $id, 'uf' => $uf, 'ibge' => strlen($ibge) === 7 ? $ibge : ''];
        }

        $official = strlen($ibge) === 7 ? $this->lookup->city($ibge) : null;

        if (! $official && ($code = $this->lookup->ibgeCode($name, $uf))) {
            $official = $this->lookup->city($code);
        }

        if (! $official) {
            return null;
        }

        $officialName = $this->upper($official['nome']);
        $officialUf = $official['uf'] ?: $uf;
        $id = $this->cityId($officialName, $officialUf) ?? $this->createCity($officialName, $officialUf, $name);

        return $id ? ['id' => $id, 'uf' => $officialUf, 'ibge' => $official['id']] : null;
    }

    private function cityId(string $name, string $uf): ?int
    {
        $uf = $this->key($uf);

        if ($this->key($name) === '' || ! preg_match('/^[A-Z]{2}$/', $uf)) {
            return null;
        }

        $this->cities[$uf] ??= DB::table('cidades as c')->join('estados as e', 'e.estados_id', '=', 'c.estados_id')
            ->whereRaw('UPPER(TRIM(e.uf)) = ?', [$uf])
            ->get(['c.cidades_id', 'c.nome'])
            ->reverse()
            ->mapWithKeys(fn ($c) => [$this->key((string) $c->nome) => (int) $c->cidades_id])
            ->all();

        return $this->cities[$uf][$this->key($name)] ?? null;
    }

    private function createCity(string $officialName, string $uf, string $informedName): ?int
    {
        if (! $stateId = $this->stateId($this->key($uf))) {
            return null;
        }

        $id = (int) DB::table('cidades')->insertGetId(['nome' => $this->cut($officialName, 255), 'estados_id' => $stateId]);
        $this->cities[$this->key($uf)][$this->key($officialName)] = $id;

        if ($this->key($informedName) !== '') {
            $this->cities[$this->key($uf)][$this->key($informedName)] = $id;
        }

        return $id;
    }

    /** estadoIdDaUf: o registro da UF que já concentra as cidades; sem sigla gravada, o que tem o nome do estado. */
    private function stateId(string $uf): ?int
    {
        $id = DB::table('estados as e')->leftJoin('cidades as c', 'c.estados_id', '=', 'e.estados_id')
            ->whereRaw('UPPER(TRIM(e.uf)) = ?', [$uf])
            ->groupBy('e.estados_id')
            ->orderByRaw('COUNT(c.cidades_id) DESC')
            ->orderBy('e.estados_id')
            ->value('e.estados_id');

        $name = array_search($uf, ServiceTaker::UF_NAMES, true);

        $id ??= $name ? DB::table('estados')->get(['estados_id', 'nome'])->first(fn ($s) => $this->key((string) $s->nome) === $this->key($name))?->estados_id : null;

        return $id ? (int) $id : null;
    }

    /** garantirSiglaNoEstado: grava a sigla de 2 letras no estado da cidade quando o cadastro só tem o nome. */
    private function ensureStateAcronym(int $cityId, string $uf): void
    {
        $uf = strtoupper(trim($uf));
        $city = DB::table('cidades')->where('cidades_id', $cityId)->first();

        if (! $city || ! preg_match('/^[A-Z]{2}$/', $uf)) {
            return;
        }

        $stateId = (int) $city->estados_id ?: (int) $this->stateId($uf);

        if ($stateId <= 0) {
            return;
        }

        if (! (int) $city->estados_id) {
            DB::table('cidades')->where('cidades_id', $cityId)->update(['estados_id' => $stateId]);
        }

        $acronym = DB::table('estados')->where('estados_id', $stateId)->value('uf');

        if (strlen(preg_replace('/[^A-Z]/', '', strtoupper((string) $acronym))) !== 2) {
            DB::table('estados')->where('estados_id', $stateId)->update(['uf' => $uf]);
        }
    }

    private function countryId(string $name): ?int
    {
        $this->countries ??= DB::table('paises')->get(['paises_id', 'nome'])->reverse()
            ->mapWithKeys(fn ($p) => [$this->key((string) $p->nome) => (int) $p->paises_id])
            ->all();

        return $this->countries[$this->key($name)] ?? null;
    }

    private function upper(mixed $text): string
    {
        return mb_strtoupper(trim((string) $text));
    }

    private function cut(string $text, int $limit): string
    {
        return mb_substr($text, 0, $limit);
    }

    /** chaveLocalidade: maiúsculas, sem acento e sem pontuação (AMPÉRE = AMPERE). */
    private function key(string $text): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', Str::upper(Str::ascii($text)));
    }
}

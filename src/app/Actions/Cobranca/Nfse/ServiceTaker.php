<?php

namespace App\Actions\Cobranca\Nfse;

use App\Actions\Cobranca\Recalculation\ChargeRates;
use App\Models\Embarque;
use App\Models\FinCharges;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Tomador da NFS-e (NfseEmissaoService legado): Consignee (IMPO) / Shipper (EXPO) do BL, com o CNPJ/CPF lido do texto,
 * ou o Cliente da reserva. Sem BL (seguro, aéreo...) é sempre o cliente; com BL, a regra do cliente
 * (fin_clients_nfse_regra.tomador) escolhe. Endereço e contato vêm do formulário e são completados pelo cadastro (pessoa).
 *
 * @phpstan-type Address array{cep:string, logradouro:string, numero:string, bairro:string, codigo_municipio:string, uf:string}
 * @phpstan-type Taker array{pessoa_id:?string, tipo:string, cpf_cnpj:string, nome:string, origem?:?string, bl_id?:?int, ref_pessoa_id?:int, endereco?:Address, telefone?:string, email?:string}
 */
class ServiceTaker
{
    public const BL = 'bl';

    public const CLIENT = 'cliente';

    public const MANUAL = 'manual';

    private const ADDRESS_KEYS = ['cep', 'logradouro', 'numero', 'bairro', 'codigo_municipio', 'uf'];

    private const DOCUMENT_LABEL = '/\b(C\.?N\.?P\.?J|C\.?N\.?J\.?P|C\.?P\.?F)\b\s*[:.\-]?\s*/i';

    private const FORMATTED_CNPJ = '/\b\d{2}\.+\d{3}\.+\d{3}\s?\/\s?\d{4}\s?\-\s?\d{2}\b/';

    private const UF_NAMES = [
        'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM', 'BAHIA' => 'BA', 'CEARA' => 'CE',
        'DISTRITO FEDERAL' => 'DF', 'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA', 'MATO GROSSO DO SUL' => 'MS',
        'MATO GROSSO' => 'MT', 'MINAS GERAIS' => 'MG', 'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE',
        'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN', 'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO',
        'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP', 'SERGIPE' => 'SE', 'TOCANTINS' => 'TO',
    ];

    /** fin_clients_nfse_regra do cliente da reserva. */
    public static function rule(Embarque $embarque): ?stdClass
    {
        $client = ChargeRates::clientId($embarque);

        return $client > 0 ? DB::table('fin_clients_nfse_regra')->where('ref_client', $client)->first() : null;
    }

    /** clienteBloqueiaEmissaoPorEmbarque: flag "bloquear emissão" da regra. */
    public static function blockedByRule(Embarque $embarque): bool
    {
        return (int) self::rule($embarque)?->bloquear_emissao === 1;
    }

    /** resolveBlDaCobranca: o BL da IC ou o único BL da reserva. */
    public function bl(FinCharges $charge): ?stdClass
    {
        if ($charge->ref_bl) {
            return DB::table('bl')->where('bl_id', $charge->ref_bl)->first();
        }

        $bls = DB::table('bl')->where('embarque_id', $charge->ref_booking)->limit(2)->get();

        return $bls->count() === 1 ? $bls->first() : null;
    }

    /** resolverOrigemTomadorPadrao. */
    public function defaultOrigin(FinCharges $charge): string
    {
        if (! $this->bl($charge)) {
            return self::CLIENT;
        }

        return self::rule($charge->embarque)?->tomador === self::CLIENT ? self::CLIENT : self::BL;
    }

    /** buildTomadorPadrao. @return Taker */
    public function default(FinCharges $charge): array
    {
        return $this->defaultOrigin($charge) === self::CLIENT ? $this->fromClient($charge) : $this->fromBl($charge);
    }

    /** buildTomadorDefaults: o padrão, ou o erro que impede montá-lo. @return array */
    public function defaultsOrError(FinCharges $charge): array
    {
        try {
            return $this->default($charge);
        } catch (DomainException $e) {
            return ['pessoa_id' => $this->defaultOrigin($charge), 'tipo' => 'pj', 'cpf_cnpj' => '', 'nome' => '', 'erro' => $e->getMessage()];
        }
    }

    /** getTomadorChoices: opções do select do formulário. @return array<string, string> */
    public function choices(FinCharges $charge): array
    {
        $choices = [];
        $role = $charge->embarque->impor_expor === 'e' ? 'Shipper do BL' : 'Consignee do BL';

        if ($this->bl($charge)) {
            try {
                $t = $this->fromBl($charge);
                $choices[self::BL] = "{$role}: {$t['nome']} ({$t['cpf_cnpj']})";
            } catch (DomainException) {
                $choices[self::BL] = "{$role} (dados inconsistentes no BL)";
            }
        }

        try {
            $t = $this->fromClient($charge);
            $choices[self::CLIENT] = "Cliente da reserva: {$t['nome']} ({$t['cpf_cnpj']})";
        } catch (DomainException) {
            $choices[self::CLIENT] = 'Cliente da reserva (dados inconsistentes no cadastro)';
        }

        return $choices + [self::MANUAL => 'Digitar tomador'];
    }

    /**
     * getTomadoresMeta: dados de cada origem para preencher o formulário quando o tomador muda
     * (o BL ganha o endereço da OpenCNPJ, o cliente o do cadastro).
     *
     * @return array<string, array>
     */
    public function meta(FinCharges $charge, bool $withAddress = false): array
    {
        $empty = fn (string $origin, string $error = '') => ['pessoa_id' => $origin, 'tipo' => 'pj', 'cpf_cnpj' => '', 'nome' => '', 'erro' => $error];
        $meta = [];

        if ($this->bl($charge)) {
            try {
                $meta[self::BL] = $this->fromBl($charge);

                if ($withAddress && $meta[self::BL]['tipo'] === 'pj') {
                    $lookup = app(AddressLookup::class)->openCnpj($meta[self::BL]['cpf_cnpj']);
                    $meta[self::BL]['endereco'] = $lookup['endereco'];
                    $meta[self::BL]['erro_endereco'] = $lookup['erro'];
                }
            } catch (DomainException $e) {
                $meta[self::BL] = $empty(self::BL, $e->getMessage());
            }
        }

        try {
            $meta[self::CLIENT] = $this->fromClient($charge);
        } catch (DomainException $e) {
            $meta[self::CLIENT] = $empty(self::CLIENT, $e->getMessage());
        }

        $client = DB::table('pessoa')->where('pessoa_id', ChargeRates::clientId($charge->embarque))->first();
        $meta[self::CLIENT]['endereco'] = $client ? $this->personAddress($client) : array_fill_keys(self::ADDRESS_KEYS, '');

        foreach ([self::BL, self::CLIENT] as $origin) {
            if (isset($meta[$origin])) {
                $meta[$origin] = $this->completeContact($meta[$origin]) + ['telefone' => '', 'email' => ''];
            }
        }

        return $meta + [self::MANUAL => $empty(self::MANUAL) + ['telefone' => '', 'email' => '']];
    }

    /** buildTomadorFromBlEmbarque: razão social e CNPJ/CPF do Consignee/Shipper. @return Taker */
    public function fromBl(FinCharges $charge): array
    {
        $e = $charge->embarque;
        $bl = $this->bl($charge) ?? throw new DomainException('Cobrança sem BL vinculado. Não é possível obter o tomador.');

        if (! in_array($e->impor_expor, ['i', 'e'], true)) {
            throw new DomainException('Emissão NFSe via BL disponível apenas para IMPO/EXPO.');
        }

        $field = $e->impor_expor === 'i' ? 'Consignee' : 'Shipper';
        $where = "{$field} do BL {$bl->bl} (id {$bl->bl_id})";
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $bl->{strtolower($field)}));

        if ($text === '') {
            throw new DomainException("Campo {$where} está vazio.");
        }

        $parsed = self::parseBlText($text);

        if (! $parsed) {
            $raw = preg_match('/\b(C\.?N\.?P\.?J|C\.?N\.?J\.?P|C\.?P\.?F)\b\s*[:.\-]?\s*([0-9][0-9\.\-\/\s]{8,30})/i', $text, $m)
                ? trim($m[2])
                : (preg_match(self::FORMATTED_CNPJ, $text, $m) ? $m[0] : null);

            throw new DomainException($raw
                ? "CNPJ/CPF inválido no {$where}: {$raw}."
                : "Não foi possível extrair razão social/CNPJ do {$where}.");
        }

        return [...$parsed, 'pessoa_id' => self::BL, 'bl_id' => (int) $bl->bl_id, 'origem' => self::BL];
    }

    /** buildTomadorFromClienteEmbarque: razão social (ou nome) e CNPJ/CPF do cadastro. @return Taker */
    public function fromClient(FinCharges $charge): array
    {
        $id = ChargeRates::clientId($charge->embarque);
        $person = $id > 0 ? DB::table('pessoa')->where('pessoa_id', $id)->first() : null;

        if ($id <= 0) {
            throw new DomainException('Reserva sem cliente vinculado.');
        }

        if (! $person) {
            throw new DomainException("Cliente da reserva (id {$id}) não encontrado.");
        }

        $name = trim((string) $person->razao_social) ?: trim((string) $person->nome);

        if ($name === '') {
            throw new DomainException("Cliente da reserva (id {$id}) sem razão social/nome no cadastro.");
        }

        $doc = self::normalizeCnpj((string) $person->cnpj);
        $digits = preg_replace('/\D+/', '', (string) $person->cnpj);
        $where = "no cadastro do cliente \"{$name}\" (id {$id})";

        [$type, $doc] = match (true) {
            $doc === '' && $digits === '' => throw new DomainException("Cliente da reserva \"{$name}\" (id {$id}) sem CPF/CNPJ no cadastro."),
            strlen($doc) === 14 => self::validCnpj($doc) ? ['pj', $doc] : throw new DomainException("CNPJ inválido {$where}: {$doc}."),
            strlen($digits) === 11 => self::validCpf($digits) ? ['pf', $digits] : throw new DomainException("CPF inválido {$where}: {$digits}."),
            default => throw new DomainException("CPF/CNPJ inválido {$where}: {$digits}."),
        };

        return ['pessoa_id' => self::CLIENT, 'tipo' => $type, 'cpf_cnpj' => $doc, 'nome' => $name, 'origem' => self::CLIENT, 'ref_pessoa_id' => $id];
    }

    /**
     * parseTomadorDoTextoBl: primeiro CNPJ/CPF válido (rotulado ou formatado) e a primeira linha não vazia antes dele como nome.
     *
     * @return ?array{nome:string, cpf_cnpj:string, tipo:string}
     */
    public static function parseBlText(string $text): ?array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $doc = self::findDocument($text);

        if (! $doc) {
            return null;
        }

        [$number, $type, $offset] = $doc;
        $name = collect(explode("\n", substr($text, 0, $offset)))->map(fn ($l) => trim($l))->first(fn ($l) => $l !== '') ?? '';
        $name = preg_replace('/^(TO\s+THE\s+ORDER\s+OF|A\s+ORDEM\s+DE|ORDER\s+OF)\s*:?\s*/i', '', $name);
        $name = trim(preg_replace('/[\s,;\-\/]*\b(C\.?N\.?P\.?J|C\.?N\.?J\.?P|C\.?P\.?F)\b\s*:?\s*$/i', '', $name), " \t.,;:-");

        return $name === '' ? null : ['nome' => $name, 'cpf_cnpj' => $number, 'tipo' => $type];
    }

    /** achaDocumentoNoTextoBl: [número, pj|pf, posição]. */
    private static function findDocument(string $text): ?array
    {
        if (preg_match_all(self::DOCUMENT_LABEL, $text, $labels, PREG_OFFSET_CAPTURE)) {
            foreach ($labels[0] as $i => [$match, $offset]) {
                $window = substr($text, $offset + strlen($match), 30);

                if (preg_match('/[^\d\.\-\/\s]/', $window, $stop, PREG_OFFSET_CAPTURE)) {
                    $window = substr($window, 0, $stop[0][1]);
                }

                $digits = preg_replace('/\D+/', '', $window);
                $isCpf = strtoupper(preg_replace('/[^A-Za-z]/', '', $labels[1][$i][0])) === 'CPF';

                if (! $isCpf && strlen($digits) >= 14 && self::validCnpj(substr($digits, 0, 14))) {
                    return [substr($digits, 0, 14), 'pj', $offset];
                }

                if (strlen($digits) >= 11 && self::validCpf(substr($digits, 0, 11))) {
                    return [substr($digits, 0, 11), 'pf', $offset];
                }
            }
        }

        foreach ([[self::FORMATTED_CNPJ, 'pj'], ['/\b\d{3}\.\d{3}\.\d{3}\-\d{2}\b/', 'pf']] as [$pattern, $type]) {
            preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE);

            foreach ($found[0] as [$match, $offset]) {
                $doc = preg_replace('/\D+/', '', $match);

                if ($type === 'pj' ? self::validCnpj($doc) : self::validCpf($doc)) {
                    return [$doc, $type, $offset];
                }
            }
        }

        return null;
    }

    /**
     * extractTomador: tomador a partir dos campos do formulário (endereco_* só quando o formulário tem endereço).
     *
     * @return Taker
     */
    public function extract(array $data): array
    {
        $type = $data['tipo'] ?? 'pj';
        $taker = [
            'pessoa_id' => filled($data['pessoa_id'] ?? null) ? (string) $data['pessoa_id'] : null,
            'tipo' => $type,
            'cpf_cnpj' => $type === 'pj' ? self::normalizeCnpj((string) ($data['cpf_cnpj'] ?? '')) : preg_replace('/\D+/', '', (string) ($data['cpf_cnpj'] ?? '')),
            'nome' => trim((string) ($data['nome'] ?? '')),
            'origem' => $data['origem'] ?? null,
            'bl_id' => isset($data['bl_id']) ? (int) $data['bl_id'] : null,
        ];

        if (isset($data['ref_pessoa_id'])) {
            $taker['ref_pessoa_id'] = (int) $data['ref_pessoa_id'];
        }

        if (self::hasAddress($data)) {
            $taker['endereco'] = $this->addressWithKeys(collect(self::ADDRESS_KEYS)->mapWithKeys(fn ($k) => [$k => $data["endereco_{$k}"] ?? ''])->all());
        }

        foreach (['telefone', 'email'] as $key) {
            if (array_key_exists($key, $data)) {
                $taker[$key] = (string) $data[$key];
            }
        }

        return $taker;
    }

    public static function hasAddress(array $data): bool
    {
        return array_key_exists('endereco_cep', $data) || array_key_exists('endereco_logradouro', $data) || array_key_exists('endereco_codigo_municipio', $data);
    }

    /** validarContatoInformado: telefone/e-mail digitados precisam ser válidos. */
    public static function validateTypedContact(array $data): void
    {
        if (filled($data['email'] ?? null) && self::email($data['email']) === '') {
            throw new DomainException('E-mail do tomador é inválido.');
        }

        if (filled($data['telefone'] ?? null) && self::phone($data['telefone']) === '') {
            throw new DomainException('Telefone do tomador deve conter números.');
        }
    }

    /** completarEnderecoTomador: separa o número do logradouro e completa o vazio com o cadastro da pessoa. @param Taker $taker @return Taker */
    public function completeAddress(array $taker): array
    {
        $address = $this->splitNumber($this->addressWithKeys($taker['endereco'] ?? []));

        if ($person = $this->person($taker)) {
            $fromPerson = $this->personAddress($person);
            $address = $this->splitNumber(collect($address)->map(fn ($v, $k) => $v === '' ? $fromPerson[$k] : $v)->all());
        }

        $address['numero'] = self::canonicalNumber($address['numero']) ?: $address['numero'];
        $taker['endereco'] = $address;

        return $taker;
    }

    /** completarContatoTomador: telefone/e-mail vazios vêm do cadastro (Telefone 1, 2 ou celular, com DDD). @param Taker $taker @return Taker */
    public function completeContact(array $taker): array
    {
        $phone = self::phone($taker['telefone'] ?? '');
        $email = self::email($taker['email'] ?? '');

        if (($phone === '' || $email === '') && ($person = $this->person($taker))) {
            $registered = self::phone(collect([$person->fone1, $person->fone2, $person->cel])->map(fn ($f) => trim((string) $f))->first(fn ($f) => $f !== ''));
            $ddd = preg_replace('/\D+/', '', (string) $person->ddd);

            if ($ddd !== '' && $registered !== '' && ! str_starts_with($registered, $ddd) && strlen($ddd.$registered) <= 20) {
                $registered = $ddd.$registered;
            }

            $phone = $phone ?: $registered;
            $email = $email ?: self::email($person->email);
        }

        unset($taker['telefone'], $taker['email']);

        return $taker + array_filter(['telefone' => $phone, 'email' => $email]);
    }

    /** validarTomador. @param Taker $taker */
    public function validate(array $taker): void
    {
        $doc = (string) ($taker['cpf_cnpj'] ?? '');

        match ($taker['tipo'] ?? '') {
            'pj' => match (true) {
                strlen(self::normalizeCnpj($doc)) !== 14 => throw new DomainException('Tomador PJ exige CNPJ com 14 caracteres.'),
                ! self::validCnpj($doc) => throw new DomainException('CNPJ do tomador é inválido (dígito verificador): '.self::normalizeCnpj($doc).'.'),
                default => null,
            },
            'pf' => match (true) {
                strlen(preg_replace('/\D+/', '', $doc)) !== 11 => throw new DomainException('Tomador PF exige CPF com 11 dígitos.'),
                ! self::validCpf($doc) => throw new DomainException('CPF do tomador é inválido (dígito verificador): '.preg_replace('/\D+/', '', $doc).'.'),
                default => null,
            },
            default => throw new DomainException('Tipo do tomador é obrigatório (PF ou PJ).'),
        };

        if (trim((string) ($taker['nome'] ?? '')) === '') {
            throw new DomainException('Nome / razão social do tomador é obrigatório.');
        }

        if (isset($taker['endereco']) && ($pending = self::addressIssues($taker['endereco']))) {
            throw new DomainException(reset($pending));
        }
    }

    /** pendenciasEnderecoTomador. @return array<string, string> */
    public static function addressIssues(array $a): array
    {
        $len = fn (string $k) => strlen(trim((string) ($a[$k] ?? '')));

        return array_filter([
            'cep' => strlen(preg_replace('/\D+/', '', (string) ($a['cep'] ?? ''))) !== 8 ? 'CEP do tomador deve ter 8 dígitos.' : null,
            'logradouro' => match (true) {
                $len('logradouro') === 0 => 'Logradouro do tomador é obrigatório.',
                $len('logradouro') > 255 => 'Logradouro do tomador deve ter no máximo 255 caracteres.',
                default => null,
            },
            'numero' => match (true) {
                $len('numero') === 0 => 'Número do tomador é obrigatório. Use S/N quando não houver número.',
                $len('numero') > 20 => 'Número do tomador deve ter no máximo 20 caracteres.',
                default => null,
            },
            'bairro' => match (true) {
                $len('bairro') === 0 => 'Bairro do tomador é obrigatório.',
                $len('bairro') > 100 => 'Bairro do tomador deve ter no máximo 100 caracteres.',
                default => null,
            },
            'codigo_municipio' => strlen(preg_replace('/\D+/', '', (string) ($a['codigo_municipio'] ?? ''))) !== 7 ? 'Código IBGE do município do tomador deve ter 7 dígitos.' : null,
            'uf' => ! preg_match('/^[A-Z]{2}$/', strtoupper(trim((string) ($a['uf'] ?? '')))) ? 'UF do tomador é obrigatória (2 letras).' : null,
        ]);
    }

    /** buildEnderecoTomadorFromPessoa: endereço do cadastro ("Rua X, 123" vira logradouro + número). @return Address */
    public function personAddress(stdClass $person): array
    {
        $uf = '';

        if ((int) $person->cidades_id > 0) {
            $state = DB::table('cidades as c')->join('estados as e', 'e.estados_id', '=', 'c.estados_id')
                ->where('c.cidades_id', $person->cidades_id)->first(['e.uf', 'e.nome']);
            $uf = $state ? self::uf((string) $state->uf, (string) $state->nome) : '';
        }

        return $this->splitNumber([
            'cep' => preg_replace('/\D+/', '', (string) $person->cep),
            'logradouro' => trim((string) $person->endereco),
            'numero' => '',
            'bairro' => trim((string) $person->bairro),
            'codigo_municipio' => preg_replace('/\D+/', '', (string) $person->codigo_municipio_ibge),
            'uf' => $uf,
        ]);
    }

    /** Campos endereco_* do formulário (defaultsFormularioEndereco). */
    public static function addressFormFields(array $address): array
    {
        $cep = preg_replace('/\D+/', '', (string) ($address['cep'] ?? ''));

        return [
            'endereco_cep' => strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : $cep,
            'endereco_logradouro' => trim((string) ($address['logradouro'] ?? '')),
            'endereco_numero' => trim((string) ($address['numero'] ?? '')),
            'endereco_bairro' => trim((string) ($address['bairro'] ?? '')),
            'endereco_codigo_municipio' => preg_replace('/\D+/', '', (string) ($address['codigo_municipio'] ?? '')),
            'endereco_uf' => strtoupper(trim((string) ($address['uf'] ?? ''))),
        ];
    }

    public static function emptyAddress(array $address): bool
    {
        return collect(self::ADDRESS_KEYS)->every(fn ($k) => trim((string) ($address[$k] ?? '')) === '');
    }

    /** normalizarTelefoneTomador: só dígitos, até 20. */
    public static function phone(mixed $raw): string
    {
        return substr(preg_replace('/\D+/', '', (string) $raw), 0, 20);
    }

    /** normalizarEmailTomador: vazio se inválido. */
    public static function email(mixed $raw): string
    {
        $email = trim((string) $raw);

        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /** crmValidatorCnpj::normalize: sem pontuação, maiúsculo (CNPJ alfanumérico). */
    public static function normalizeCnpj(string $value): string
    {
        return strtoupper(preg_replace('/[\s.\-\/]/', '', $value));
    }

    /** crmValidatorCnpj::isValid: numérico ou alfanumérico (12 alfanuméricos + 2 DV, valor ASCII − 48). */
    public static function validCnpj(string $cnpj): bool
    {
        $cnpj = self::normalizeCnpj($cnpj);

        if (! preg_match('/^[0-9A-Z]{12}[0-9]{2}$/', $cnpj) || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $dv = function (string $base): int {
            $weights = array_slice([6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], 13 - strlen($base));
            $sum = 0;

            foreach (str_split($base) as $i => $char) {
                $sum += (ord($char) - 48) * $weights[$i];
            }

            return $sum % 11 < 2 ? 0 : 11 - $sum % 11;
        };

        $first = $dv(substr($cnpj, 0, 12));

        return substr($cnpj, 12) === $first.$dv(substr($cnpj, 0, 12).$first);
    }

    public static function validCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D+/', '', $cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }

            if ((int) $cpf[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }

        return true;
    }

    /** numeroCanonico: "Nº 12" → "12", "s/nr" → "S/N"; vazio se não parece número de endereço. */
    public static function canonicalNumber(string $token): string
    {
        $token = strtoupper(rtrim(preg_replace('/^N(?:º|°|\.)+\s*/u', '', trim($token)), " \t."));
        $token = preg_match('/^S\/?NR?$/', $token) ? 'S/N' : $token;

        return $token !== '' && strlen($token) <= 20
            && preg_match('/^(?:S\/N|(?:\d{1,3}(?:\.\d{3})+|\d+)[A-Z]?(?:[\s\/\-]+[A-Z0-9]{1,6})?)$/', $token) ? $token : '';
    }

    /** siglaDaUf: sigla a partir da sigla ou do nome do estado. */
    public static function uf(string $uf, string $name = ''): string
    {
        foreach ([$uf, $name] as $text) {
            $upper = strtoupper(trim($text));

            if (strlen(preg_replace('/[^A-Z]/', '', $upper)) === 2) {
                return preg_replace('/[^A-Z]/', '', $upper);
            }

            $key = trim(preg_replace('/[^A-Z]+/', ' ', strtoupper(Str::ascii($text))));

            if (isset(self::UF_NAMES[$key])) {
                return self::UF_NAMES[$key];
            }
        }

        return '';
    }

    /** pessoaDoTomador: pelo cliente, pelo id ou pelo CNPJ. */
    private function person(array $taker): ?stdClass
    {
        $id = $taker['ref_pessoa_id'] ?? (is_numeric($taker['pessoa_id'] ?? null) ? (int) $taker['pessoa_id'] : null);

        if ($id && ($person = DB::table('pessoa')->where('pessoa_id', $id)->first())) {
            return $person;
        }

        $doc = self::normalizeCnpj((string) ($taker['cpf_cnpj'] ?? ''));

        return $doc === '' ? null : DB::table('pessoa')
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(cnpj, '.', ''), '/', ''), '-', ''), ' ', '')) = ?", [$doc])
            ->first();
    }

    /** enderecoTomadorComChaves. @return Address */
    private function addressWithKeys(array $address): array
    {
        $a = collect(self::ADDRESS_KEYS)->mapWithKeys(fn ($k) => [$k => trim((string) ($address[$k] ?? ''))])->all();
        $a['cep'] = preg_replace('/\D+/', '', $a['cep']);
        $a['codigo_municipio'] = preg_replace('/\D+/', '', $a['codigo_municipio']);
        $a['uf'] = strtoupper($a['uf']);

        return $a;
    }

    /** aplicarNumeroDoLogradouro + separarNumeroLogradouro: "Rua X, 123, sala 2" → número 123 quando o número está vazio. @return Address */
    private function splitNumber(array $address): array
    {
        if (trim((string) $address['numero']) !== '') {
            return $address;
        }

        $street = trim((string) $address['logradouro']);
        $number = '';

        while ($street !== '' && preg_match('/^(.*?),\s*([^,]{1,20})$/', $street, $m)) {
            $token = self::canonicalNumber($m[2]);

            // "Rua 7 de Setembro, 12" não é "Rua 7 de Setembro" nº 12 quando o resto termina em dígito
            if ($token === '' || (preg_match('/^\d{1,2}$/', $token) && preg_match('/\d$/', rtrim($m[1])))) {
                break;
            }

            $number = $number ?: $token;
            $street = trim($m[1], " \t,");
        }

        return $number === '' ? $address : ['logradouro' => $street, 'numero' => $number] + $address;
    }
}

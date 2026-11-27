<?php

namespace App\Actions\Cobranca\Boleto;

use App\Actions\Cobranca\Listing\ChargeDueDate;
use App\Actions\Cobranca\Nfse\AddressLookup;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Models\FinCharges;
use App\Models\FinChargesNfse;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * SicrediBoletoService::montarPayload (legado): o cadastro do boleto, com juros de 0,89% ao dia, multa de 2% e protesto
 * em 5 dias. O pagador é o mesmo tomador fiscal da NFS-e.
 */
class BoletoPayload
{
    public function __construct(
        private ServiceTaker $taker,
        private AddressLookup $lookup,
        private ChargeDueDate $dueDate,
    ) {}

    public function __invoke(FinCharges $charge, ?string $due = null): array
    {
        $due = filled($due) ? CarbonImmutable::parse($due)->startOfDay() : $this->suggestedDueDate($charge);

        if (! $due->greaterThan(CarbonImmutable::today())) {
            throw new DomainException('A data de vencimento do boleto deve ser posterior ao dia atual.');
        }

        $value = self::value($charge);

        if ($value <= 0) {
            throw new DomainException('Valor da cobrança inválido para gerar boleto.');
        }

        $afterDue = $due->addDay()->toDateString();

        return [
            'tipoCobranca' => 'NORMAL',
            'codigoBeneficiario' => SicrediApi::beneficiary(),
            'especieDocumento' => 'DUPLICATA_SERVICO_INDICACAO',
            'seuNumero' => self::ourReference($charge),
            'dataVencimento' => $due->toDateString(),
            'valor' => $value,
            'pagador' => $this->payer($charge),
            'tipoJuros' => 'PERCENTUAL',
            'tipoJurosPercentual' => 'DIARIO',
            'juros' => 0.89,
            'dataInicioJuros' => $afterDue,
            'tipoMulta' => 'PERCENTUAL',
            'multa' => 2.00,
            'dataInicioMulta' => $afterDue,
            'diasProtestoAuto' => 5,
            'mensagens' => [
                'Juros de 0,89% ao dia apos o vencimento.',
                'Multa de 2% apos o vencimento.',
                'Protesto 5 dias apos o vencimento.',
            ],
        ];
    }

    /** sugerirDataVencimento: o prazo da IC se for futuro, senão amanhã. */
    public function suggestedDueDate(FinCharges $charge): CarbonImmutable
    {
        $due = $this->dueDate->final($charge);

        return $due && $due->greaterThan(CarbonImmutable::today()) ? $due : CarbonImmutable::tomorrow();
    }

    /** resolverValor: total + taxa de boleto − desconto. */
    public static function value(FinCharges $charge): float
    {
        return round((float) $charge->total_value + max(0, (float) $charge->tax_boleto) - max(0, (float) $charge->discount), 2);
    }

    /** montarSeuNumero: até 10 caracteres. */
    private static function ourReference(FinCharges $charge): string
    {
        $reference = 'TRK'.str_pad((string) $charge->id, 7, '0', STR_PAD_LEFT);

        return strlen($reference) > 10 ? substr((string) $charge->id, -10) : $reference;
    }

    private function payer(FinCharges $charge): array
    {
        $taker = $this->taker->completeContact($this->taker->completeAddress($this->taker($charge)));
        $person = ($taker['tipo'] ?? '') === 'pf' ? 'pf' : 'pj';
        $document = $person === 'pf' ? preg_replace('/\D+/', '', (string) $taker['cpf_cnpj']) : ServiceTaker::normalizeCnpj((string) $taker['cpf_cnpj']);

        $address = $taker['endereco'];
        $street = trim($address['logradouro']);
        $number = trim($address['numero']);
        // não repete o número que o logradouro do BL já traz
        $line = $number !== '' && ! str_contains($street, $number) ? trim("{$street} {$number}") : $street;
        $zip = preg_replace('/\D+/', '', $address['cep']);
        $state = strtoupper(trim($address['uf']));
        $city = $this->city($taker);

        if ($line === '' || $city === '' || $state === '' || strlen($zip) !== 8) {
            throw new DomainException('Endereço do pagador (tomador da NFS-e) incompleto (logradouro, cidade, UF e CEP com 8 dígitos são obrigatórios).');
        }

        return [
            'tipoPessoa' => $person === 'pf' ? 'PESSOA_FISICA' : 'PESSOA_JURIDICA',
            'documento' => $document,
            'nome' => mb_substr(trim((string) $taker['nome']), 0, 40), // limite da API
            'endereco' => $line,
            'cidade' => $city,
            'uf' => $state,
            'cep' => $zip,
        ] + array_filter([
            'telefone' => preg_replace('/\D+/', '', (string) ($taker['telefone'] ?? '')),
            'email' => (string) ($taker['email'] ?? ''),
        ]);
    }

    /** resolverTomadorParaBoleto: o tomador da última NFS-e autorizada; sem ela, a regra padrão (BL ou cliente). */
    private function taker(FinCharges $charge): array
    {
        $issued = FinChargesNfse::where('ref_charge', $charge->id)->where('status', 'autorizada')->orderByDesc('fin_charges_nfse_id')->pluck('tomador_json');

        foreach ($issued as $json) {
            $taker = json_decode((string) $json, true);

            if (is_array($taker) && ! empty($taker['cpf_cnpj']) && ! empty($taker['nome'])) {
                return $taker;
            }
        }

        return $this->taker->default($charge);
    }

    /** resolverNomeCidade: a do cadastro da pessoa; sem ela, o nome do município pelo código IBGE. */
    private function city(array $taker): string
    {
        $id = $taker['ref_pessoa_id'] ?? (is_numeric($taker['pessoa_id'] ?? null) ? $taker['pessoa_id'] : null);
        $document = ServiceTaker::normalizeCnpj((string) $taker['cpf_cnpj']);
        $people = DB::table('pessoa as p')->join('cidades as c', 'c.cidades_id', '=', 'p.cidades_id');

        $city = $id
            ? (clone $people)->where('p.pessoa_id', $id)->value('c.nome')
            : $people->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(p.cnpj, '.', ''), '/', ''), '-', ''), ' ', '')) = ?", [$document])->value('c.nome');

        return trim((string) ($city ?: ($this->lookup->city((string) ($taker['endereco']['codigo_municipio'] ?? ''))['nome'] ?? '')));
    }
}

<?php

namespace Tests\Unit;

use App\Actions\Cobranca\Nfse\IssueNfse;
use App\Actions\Cobranca\Nfse\ServiceTaker;
use App\Models\FinChargesNfse;
use DomainException;
use Tests\TestCase;

class NfseRulesTest extends TestCase
{
    public function test_reads_taker_name_and_cnpj_from_bl_text(): void
    {
        $text = "TO THE ORDER OF: ACME IMPORTADORA LTDA\nRUA X, 100\nCNPJ: 07.052.088/0001-34";

        $this->assertSame(
            ['nome' => 'ACME IMPORTADORA LTDA', 'cpf_cnpj' => '07052088000134', 'tipo' => 'pj'],
            ServiceTaker::parseBlText($text),
        );
    }

    public function test_skips_invalid_cnpj_and_falls_back_to_cpf(): void
    {
        $text = "FULANO DE TAL\nCNPJ 11.111.111/1111-11\nCPF: 529.982.247-25";

        $this->assertSame('52998224725', ServiceTaker::parseBlText($text)['cpf_cnpj']);
        $this->assertSame('pf', ServiceTaker::parseBlText($text)['tipo']);
        $this->assertNull(ServiceTaker::parseBlText('SEM DOCUMENTO NENHUM'));
    }

    public function test_validates_numeric_and_alphanumeric_cnpj_and_cpf(): void
    {
        $this->assertTrue(ServiceTaker::validCnpj('07.052.088/0001-34'));
        $this->assertFalse(ServiceTaker::validCnpj('07.052.088/0001-35'));
        $this->assertTrue(ServiceTaker::validCnpj('12ABC34501DE35'));
        $this->assertFalse(ServiceTaker::validCnpj('11111111111111'));
        $this->assertTrue(ServiceTaker::validCpf('529.982.247-25'));
        $this->assertFalse(ServiceTaker::validCpf('111.111.111-11'));
    }

    public function test_normalizes_address_number_and_state(): void
    {
        $this->assertSame('123', ServiceTaker::canonicalNumber('Nº 123'));
        $this->assertSame('S/N', ServiceTaker::canonicalNumber('s/nr'));
        $this->assertSame('', ServiceTaker::canonicalNumber('sala'));
        $this->assertSame('RS', ServiceTaker::uf('', 'Rio Grande do Sul'));
        $this->assertSame('SP', ServiceTaker::uf('São Paulo'));
    }

    public function test_lists_address_issues(): void
    {
        $issues = ServiceTaker::addressIssues(['cep' => '96810-034', 'logradouro' => 'Rua Borges de Medeiros', 'numero' => '', 'bairro' => 'Centro', 'codigo_municipio' => '4316808', 'uf' => 'rs']);

        $this->assertSame(['numero'], array_keys($issues));
    }

    public function test_parses_money_competence_and_substitution_reason(): void
    {
        $this->assertSame(1234.56, IssueNfse::money('R$ 1.234,56'));
        $this->assertSame(10.5, IssueNfse::money('10.50'));
        $this->assertNull(IssueNfse::money('0,00'));
        $this->assertSame('2026-10-27', IssueNfse::competence('27/10/2026'));
        $this->assertSame('01', FinChargesNfse::substitutionReason('1'));
        $this->assertSame('', FinChargesNfse::substitutionReason('7'));

        $this->expectException(DomainException::class);
        IssueNfse::competence('31/02/2026');
    }
}

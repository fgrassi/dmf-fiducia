<?php

namespace App\Actions\Companies;

use App\Models\Pessoa;
use Illuminate\Support\Facades\DB;

/**
 * pessoa/update (legado): grava a empresa, os tipos, os armadores permitidos na cotação e o escritório comercial,
 * e registra o histórico ("Cadastro realizado" / snapshot dos dados na atualização), numa transação.
 */
class SaveCompany
{
    /** Tipo 1 não aparece no formulário do legado; os demais são sincronizados com a seleção. */
    private const HIDDEN_TYPE = 1;

    private const HISTORY_FIELDS = [
        'nome' => 'Nome',
        'razao_social' => 'Razão Social',
        'cnpj' => 'CNPJ',
        'rnc' => 'RNC',
        'ie' => 'I.E',
        'fone1' => 'Telefone',
        'domain' => 'Domínio',
        'histCompleto' => 'Receber Históricos Completos',
        'histPdf' => 'Enviar Tracking com PDF',
        'histtxt' => 'Enviar Tracking em TXT',
        'reportHtml' => 'Enviar Relatório em HTML',
        'envio_ind' => 'Enviar Relatórios Coletivos',
        'ctr_liberacao_hbl_origem' => 'Controlar Envio Liberacao HBL Origem',
        'ctr_envio_valores' => 'Controlar Envio de Valores Finais ao Despachante',
        'ctr_liberacao' => 'Controlar Liberacao dos Embarques no Destino',
        'bairro' => 'Bairro',
        'endereco' => 'Endereço',
        'cep' => 'CEP',
        'per_desc' => 'Perc. desc',
        'obs' => 'Obs',
        'obs_cotacao' => 'Obs Cotação Impo',
        'nf_completa' => 'Nf Completa',
        'isento_iof' => 'Isento IOF',
        'seguro_obrigatorio' => 'Seguro Obrigatório',
        'separa_taxas' => 'Separa Taxas',
    ];

    /** @param  array<string, mixed>  $data  colunas de pessoa + tipo_empresa, armadores_permitidos e pessoa_sede */
    public function __invoke(array $data, int $userId, ?Pessoa $company = null): Pessoa
    {
        $types = $data['tipo_empresa'] ?? [];
        $carriers = $data['armadores_permitidos'] ?? [];
        $officeId = $data['pessoa_sede'] ?? null;
        unset($data['tipo_empresa'], $data['armadores_permitidos'], $data['pessoa_sede']);

        return DB::transaction(function () use ($data, $userId, $company, $types, $carriers, $officeId) {
            $company ??= new Pessoa;
            $isNew = ! $company->exists;

            $company->forceFill($data + ($isNew
                ? ['dteCadastro' => now(), 'created_by' => $userId]
                : ['dteMovimentacao' => now(), 'updated_by' => $userId]))->save();

            $id = $company->getKey();

            DB::table('pessoa_tipo_pessoa')->where('pessoa_id', $id)->where('tipo_pessoa_id', '<>', self::HIDDEN_TYPE)->delete();
            DB::table('pessoa_tipo_pessoa')->insert(array_map(fn ($type) => ['pessoa_id' => $id, 'tipo_pessoa_id' => $type], $types));

            DB::table('pessoa_armador')->where('pessoa_id', $id)->delete();
            DB::table('pessoa_armador')->insert(array_map(fn ($carrier) => [
                'pessoa_id' => $id,
                'armador_id' => $carrier,
                'created_at' => now(),
                'created_by' => $userId,
            ], array_unique($carriers)));

            $office = DB::table('pessoa_sede')->where('pessoa_id', $id);

            if (! $officeId) {
                $office->delete();
            } elseif ($office->exists()) {
                $office->update(['sede_id' => $officeId]);
            } else {
                $office->insert(['pessoa_id' => $id, 'sede_id' => $officeId, 'created_at' => now(), 'created_by' => $userId]);
            }

            DB::table('historico_pessoa')->insert([
                'pessoa_id' => $id,
                'assunto' => $isNew ? 'Cadastro realizado' : 'Atualização dos dados',
                'historico' => $isNew ? null : $this->snapshot($company),
                'created_at' => now(),
                'created_by' => $userId,
            ]);

            return $company;
        });
    }

    private function snapshot(Pessoa $company): string
    {
        return collect(self::HISTORY_FIELDS)
            ->map(fn (string $label, string $field) => '<p>'.e($label).': '.e($this->yesNo($company->{$field})).'</p>')
            ->implode('');
    }

    private function yesNo(mixed $value): string
    {
        return match ($value) {
            's', 'true', '1', 1, true => 'Sim',
            'n', 'false', '0', 0, false => 'Não',
            default => (string) $value,
        };
    }
}

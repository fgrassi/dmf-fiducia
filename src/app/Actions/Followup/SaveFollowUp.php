<?php

namespace App\Actions\Followup;

use App\Actions\Notices\SendNoticeMail;
use App\Models\AvaliacaoCliente;
use App\Models\CmnClientFup;
use App\Models\Pessoa;
use App\Models\User;
use App\Models\VisitaCliente;
use Illuminate\Support\Facades\DB;

/**
 * followup/update (legado). Um follow up novo registra também a avaliação do cliente (a anterior do mesmo tipo é encerrada); um
 * existente só muda a data e o relato, e avisa a gerência se já tinha sido enviado no relatório. Em ambos, data do próximo contato
 * ou observação abrem um novo contato com o cliente, encerrando o que estava em aberto.
 */
class SaveFollowUp
{
    /** Quem recebe o aviso de um follow up já enviado que foi alterado (fixos no legado). */
    private const CHANGE_NOTICE_TO = ['priscila@dmflog.com.br', 'fernando@dmflog.com.br'];

    /**
     * @param  array{ref_pessoa?: int, tipo?: string, volume_embarques?: int, abertura?: int, cidades_id?: ?int, data_ocorrencia: string, descr: string, data_proximo_contato?: ?string, obs?: ?string}  $data
     */
    public function __invoke(array $data, User $user, ?CmnClientFup $fup = null): CmnClientFup
    {
        return DB::transaction(function () use ($data, $user, $fup) {
            $fup ? $this->update($fup, $data, $user) : $fup = $this->create($data, $user);

            if (array_key_exists('cidades_id', $data)) {
                Pessoa::whereKey($fup->ref_pessoa)->update(['cidades_id' => $data['cidades_id']]);
            }

            if (filled($data['data_proximo_contato'] ?? null) || filled($data['obs'] ?? null)) {
                VisitaCliente::where('pessoa_id', $fup->ref_pessoa)->where('status', 0)->update(['status' => 1]);
                VisitaCliente::create([
                    'usuario_id' => $user->getKey(),
                    'pessoa_id' => $fup->ref_pessoa,
                    'data_cadastro' => today()->toDateString(),
                    'data_proximo_contato' => $data['data_proximo_contato'] ?? null,
                    'obs' => $data['obs'] ?? null,
                    'status' => 0,
                ]);
            }

            return $fup;
        });
    }

    private function create(array $data, User $user): CmnClientFup
    {
        $rating = ['volume_embarques' => (int) ($data['volume_embarques'] ?? 0), 'abertura' => (int) ($data['abertura'] ?? 0)];

        $fup = CmnClientFup::create([
            'created_at' => today()->toDateString(),
            'ref_user' => $user->getKey(),
            'status' => 0,
            'ref_pessoa' => $data['ref_pessoa'],
            'data_ocorrencia' => $data['data_ocorrencia'],
            'tipo' => $data['tipo'],
            'descr' => $data['descr'],
            ...$rating,
        ]);

        AvaliacaoCliente::where('pessoa_id', $fup->ref_pessoa)->where('tipo', $fup->tipo)->where('status', 0)->update(['status' => 1]);
        AvaliacaoCliente::create(['pessoa_id' => $fup->ref_pessoa, 'tipo' => $fup->tipo, 'created_at' => today()->toDateString(), ...$rating]);

        return $fup;
    }

    private function update(CmnClientFup $fup, array $data, User $user): void
    {
        if ($fup->status == 1) {
            (new SendNoticeMail)(
                'FUP alterado',
                'O FUP abaixo já havia sido enviado e foi alterado pelo usuario '.e($user->nome)
                .' <br />Cliente: '.e($fup->pessoa?->nome).'<br /><br />Antiga descricão: <br />'.nl2br(e($fup->descr))
                .' <br /> Nova descricão: <br />'.nl2br(e($data['descr'])),
                self::CHANGE_NOTICE_TO,
                from: 'system@dmflog.com.br',
            );
        }

        $fup->update(['data_ocorrencia' => $data['data_ocorrencia'], 'descr' => $data['descr']]);
    }
}

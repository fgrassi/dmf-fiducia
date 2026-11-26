<?php

namespace App\Actions\Booking\Creation;

use App\Models\ConsultaCnpj;
use App\Models\Pessoa;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** As partes da reserva (cliente, consignee, shipper e notify): contatos e restrições da empresa. */
class BookingParties
{
    /** parte => [coluna da empresa na reserva, tabela dos contatos, rótulo] */
    public const PARTIES = [
        'cliente' => ['cliente_id', 'embarque_contato_cliente', 'Cliente'],
        'importador' => ['pessoa_id_importador', 'embarque_contato_importador', 'Consignee'],
        'exportador' => ['pessoa_id_cliente', 'embarque_contato', 'Shipper'],
        'notify' => ['pessoa_id_notify', 'embarque_contato_notify', 'Notify'],
    ];

    /** getUsuariosPessoa (legado): usuários ativos da empresa no tipo da operação. @return array<int, string> */
    public function contactOptions(int|string|null $personId, string $operation): array
    {
        return $personId
            ? $this->contacts($personId, $operation)->orderBy('u.nome')->pluck('u.nome', 'u.usuario_id')->all()
            : [];
    }

    /** Pessoa::getContatoPadrao (legado): os que já entram na reserva quando a empresa é escolhida. @return list<int> */
    public function defaultContacts(int|string|null $personId, string $operation): array
    {
        return $personId
            ? $this->contacts($personId, $operation)->where('u.usuario_padrao', 's')->orderBy('u.nome')->pluck('u.usuario_id')->all()
            : [];
    }

    /** Restrição total: a empresa não pode entrar na reserva. */
    public function isBlacklisted(int|string $personId): bool
    {
        return (int) Pessoa::query()->whereKey($personId)->value('ctr_blacklist') === 1;
    }

    /**
     * Avisos que o legado abria ao escolher a empresa: blacklist, restrições parciais, consulta de CNPJ e observação do cadastro.
     *
     * @return list<string>
     */
    public function warnings(int|string|null $personId, bool $checkCnpj, bool $showNote): array
    {
        $person = $personId ? Pessoa::find($personId) : null;

        if (! $person) {
            return [];
        }

        $warnings = [];

        if ((int) $person->ctr_blacklist === 1) {
            $reason = DB::table('blacklist_pessoa')->where('pessoa_id', $personId)->where('status', 1)->value('justificativa');
            $warnings[] = trim('Empresa na blacklist do sistema. '.$reason);
        }

        foreach (DB::table('restricao_pessoa')->where('pessoa_id', $personId)->where('status', 1)->pluck('descricao') as $description) {
            $warnings[] = "Restrição parcial: {$description}";
        }

        if ($checkCnpj && ($status = $person->cnpjStatus()) !== ConsultaCnpj::OK) {
            $warnings[] = 'Consulta de CNPJ: '.($status === ConsultaCnpj::NONE ? 'Não consultado' : ConsultaCnpj::LABELS[$status]);
        }

        if ($showNote && filled($person->obs)) {
            $warnings[] = "Observação da empresa: {$person->obs}";
        }

        return $warnings;
    }

    private function contacts(int|string $personId, string $operation): Builder
    {
        return DB::table('usuario as u')
            ->join('pessoa_usuario as pu', 'pu.usuario_id', '=', 'u.usuario_id')
            ->join('pessoa_usuario_tipo_usuario as t', 't.pessoa_usuario_id', '=', 'pu.pessoa_usuario_id')
            ->where('pu.pessoa_id', $personId)
            ->where('t.tipo_usuario_id', $operation === 'i' ? 7 : 3)
            ->where('u.status', 1);
    }
}

<?php

namespace App\Actions\Brokers;

use App\Models\Despachante;
use App\Models\DespachanteContato;
use Illuminate\Support\Facades\DB;

/**
 * despachante/addContato (legado): grava ou altera o contato. Com justificativa (e-mail fora do domínio do despachante) ele
 * fica aguardando aprovação (status 0); sem ela entra direto (status 1).
 *
 *  sem a aprovação (popup global de autorizações do legado) nem o e-mail dela; aprovar segue no legado.
 */
class SaveBrokerContact
{
    /** @param  array{name: string, email: string, tipo?: ?string, impor_expor?: ?string, justificativa?: ?string}  $data */
    public function __invoke(Despachante $broker, array $data, int $userId, ?DespachanteContato $contact = null): DespachanteContato
    {
        $justification = $data['justificativa'] ?? null;

        return DB::transaction(function () use ($broker, $data, $userId, $justification, $contact) {
            $contact ??= new DespachanteContato(['ref_despachante' => $broker->getKey()]);

            $contact->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'tipo' => $data['tipo'] ?? '',
                'impor_expor' => $data['impor_expor'] ?? null,
                'status' => filled($justification) ? 0 : 1,
            ])->save();

            if (filled($justification)) {
                DB::table('justificativa_contato_despachante')->insert([
                    'justificativa' => $justification,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'tipo_usuario' => auth()->user()?->hasLegacyCredential('import') ? 'i' : 'e',
                    'despachante_contato_id' => $contact->getKey(),
                ]);
            }

            return $contact;
        });
    }
}

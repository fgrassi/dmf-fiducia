<?php

namespace App\Actions\Assessoria;

use App\Models\Assessoria;
use App\Models\AssessoriaContato;
use Illuminate\Support\Facades\DB;

/**
 * assessoria/addContato (legado): grava o contato. Com justificativa (e-mail fora do domínio da assessoria) ele fica
 * aguardando aprovação (status 0); sem ela entra direto (status 1).
 *
 *  sem a aprovação (popup global de autorizações do legado) nem o e-mail dela; aprovar segue no legado.
 */
class SaveAssessoriaContact
{
    /** @param  array{nome: string, email: string, justificativa?: ?string}  $data */
    public function __invoke(Assessoria $assessoria, array $data, int $userId): AssessoriaContato
    {
        $justification = $data['justificativa'] ?? null;

        return DB::transaction(function () use ($assessoria, $data, $userId, $justification) {
            $contact = $assessoria->contacts()->create([
                'nome' => $data['nome'],
                'email' => $data['email'],
                'status' => filled($justification) ? 0 : 1,
            ]);

            if (filled($justification)) {
                DB::table('justificativa_contato_assessoria')->insert([
                    'justificativa' => $justification,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'tipo_usuario' => auth()->user()?->hasLegacyCredential('import') ? 'i' : 'e',
                    'assessoria_contato_id' => $contact->getKey(),
                ]);
            }

            return $contact;
        });
    }
}

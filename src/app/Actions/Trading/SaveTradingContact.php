<?php

namespace App\Actions\Trading;

use App\Models\Trading;
use App\Models\TradingContato;
use Illuminate\Support\Facades\DB;

/**
 * trading/addContato (legado): grava o contato. Com justificativa (e-mail fora do domínio da trading) ele fica
 * aguardando aprovação (status 0); sem ela entra direto (status 1).
 *
 *  sem a aprovação (popup global de autorizações do legado) nem o e-mail dela; aprovar segue no legado.
 */
class SaveTradingContact
{
    /** @param  array{nome: string, email: string, justificativa?: ?string}  $data */
    public function __invoke(Trading $trading, array $data, int $userId): TradingContato
    {
        $justification = $data['justificativa'] ?? null;

        return DB::transaction(function () use ($trading, $data, $userId, $justification) {
            $contact = $trading->contacts()->create([
                'nome' => $data['nome'],
                'email' => $data['email'],
                'status' => filled($justification) ? 0 : 1,
            ]);

            if (filled($justification)) {
                DB::table('justificativa_contato_trading')->insert([
                    'justificativa' => $justification,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'tipo_usuario' => auth()->user()?->hasLegacyCredential('import') ? 'i' : 'e',
                    'trading_contato_id' => $contact->getKey(),
                ]);
            }

            return $contact;
        });
    }
}

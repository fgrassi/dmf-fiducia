<?php

namespace App\Actions\Cobranca\Generation;

use App\Models\Embarque;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * geraCobrancaManual (legado): registra a cobranca_manual da reserva, gera a IC (só das despesas escolhidas, se houver)
 * e avisa o financeiro de que a geração foi manual.
 */
class GenerateManualCharge
{
    public function __construct(private GenerateCharges $generate) {}

    /**
     * @param  ?list<int>  $expenseIds  fin_expenses_values escolhidas; null = todas as pendentes
     * @return int ICs criadas
     */
    public function __invoke(Embarque $b, ?array $expenseIds, User $user): int
    {
        $id = DB::table('cobranca_manual')->insertGetId([
            'embarque_id' => $b->embarque_id,
            'created_at' => now(),
            'created_by' => $user->getKey(),
        ]);

        $created = ($this->generate)(
            [[$b->embarque_id, fn () => DB::table('cobranca_manual')->where('cobranca_manual_id', $id)->update(['control' => 1])]],
            $expenseIds ?: null,
            $user->getKey(),
        );

        BookingChecks::notify("Cobrança gerada manualmente:{$b->booking}",
            "<p>A reserva {$b->booking} teve a cobrança gerada manualmente pelo usuario {$user->getFilamentName()}.");

        return $created;
    }
}

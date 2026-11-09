<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * executeCreateDebitNote / executeCreateCreditNote (nota vazia) e executeGeraDebitNote / executeGeraCreditNote
 * (nota já com as despesas marcadas): cria a nota da reserva para o agente, com a cotação do dólar do dia, o texto de endereço
 * de alguns agentes, o número "DN 0001/2026" e o rastreamento.
 */
class CreateNote
{
    /** Agentes cujo endereço de cobrança vai nas observações da nota. */
    private const AGENT_NOTES = [
        3306 => "ZACH LOGISTICS SA DE CV\nBLVD VICENTE VALTIERRA 7020 - C\nCOL. CAÑADA DE ALFARO\nC.P. 37238\nLEON, GUANAJUATO. MEXICO\nTAX ID: ZLO110704D18\nTEL.: +52 55 5654 9710",
        14034 => "HENCO GLOBAL S.A. DE C.V.\nROBLE NO. 668-A COLONIA URDIALES\nMONTERREY N.L. MÉXICO CP 64430\nRFC : HGL-980417-J43",
        15999 => "MARINE TRUST S.A. DE C.V.\nRFC MTR181010B44\nMORELOS NÚMERO 1864 15, AMERICANA,\nGUADALAJARA, JALISCO, CP 44160",
    ];

    /** Só a nota de crédito criada vazia tinha este (executeCreateCreditNote). */
    private const CREDIT_ONLY_AGENT_NOTES = [
        3586 => "TECO INT. PROJECT & ANLAGEN SPEDITIONS GMBH\nLUDENBERGER STRASSE 58\n40629 DÜSSELDORF",
    ];

    /** Cotação do dólar: a última de fin_currencies_rates (id 1). */
    private const DOLLAR_RATE = 1;

    public function __construct(private NoteTracking $tracking, private RecalculateNote $recalculate) {}

    /**
     * @param  list<int>  $expenseIds  despesas da reserva para já entrar na nota; vazio cria a nota sem itens
     * @return int id da nota
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, NoteType $type, array $expenseIds, int $userId): int
    {
        if (! $booking->pessoa_id_representante) {
            throw new DomainException('Não há agente cadastrado para esse embarque.');
        }

        $fromExpenses = $expenseIds !== [];
        $valid = DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->whereIn('id', $expenseIds)->pluck('id');

        return DB::transaction(function () use ($booking, $type, $userId, $fromExpenses, $valid) {
            $noteId = DB::table($type->table())->insertGetId([
                'ref_embarque' => $booking->getKey(),
                'ref_agente' => $booking->pessoa_id_representante,
                'created_at' => now()->toDateString(),
                'created_by' => $userId,
                'status_pagamento' => 0,
                'dolar_rate' => DB::table('fin_currencies_rates_values')->where('ref_currency_rate', self::DOLLAR_RATE)->orderByDesc('id')->value('rate'),
                ...($type === NoteType::Debit ? ['data_vencimento' => now()->addDays($booking->tipo_embarque === 'm' ? 30 : 7)->toDateString()] : []),
            ]);

            DB::table($type->table())->where('id', $noteId)->update([
                'obs' => $this->agentNote($type, $fromExpenses, (int) $booking->pessoa_id_representante),
                'name' => $this->prefix($type, $booking, $fromExpenses).' '.str_pad($noteId, 4, '0', STR_PAD_LEFT).'/'.now()->format('Y'),
            ]);

            $this->tracking->note($type, $noteId, 'i', $userId);

            if ($fromExpenses) {
                DB::table($type->itemsTable())->insert($valid->map(fn ($id) => [$type->itemColumn() => $noteId, 'ref_expense' => $id])->all());
                ($this->recalculate)($type, $noteId);
            }

            return $noteId;
        });
    }

    /** Importação cria "IN" na nota de crédito vazia; a gerada pelas despesas é sempre "CN" (como o legado). */
    private function prefix(NoteType $type, Embarque $booking, bool $fromExpenses): string
    {
        return $type === NoteType::Credit && $booking->impor_expor === 'i' && ! $fromExpenses ? 'IN' : $type->abbreviation();
    }

    private function agentNote(NoteType $type, bool $fromExpenses, int $agentId): ?string
    {
        $notes = self::AGENT_NOTES + ($type === NoteType::Credit && ! $fromExpenses ? self::CREDIT_ONLY_AGENT_NOTES : []);

        // O endereço da Zach vinha com nl2br no legado.
        return isset($notes[$agentId]) ? ($agentId === 3306 ? nl2br($notes[$agentId]) : $notes[$agentId]) : null;
    }
}

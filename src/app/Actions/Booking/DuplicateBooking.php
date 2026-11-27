<?php

namespace App\Actions\Booking;

use App\Actions\Booking\Creation\HistorySnapshot;
use App\Actions\Booking\Creation\ImportExpenseMatrix;
use App\Enums\AmsDestination;
use App\Models\Embarque;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * booking/duplicar e booking/duplicarExpo (legado; a segunda é a da exportação marítima, que pergunta a Freight Invoice).
 *
 * Como no legado, a cópia leva os dados da reserva e as despesas. Mercadorias, contatos, BLs e rastreamento não são copiados.
 */
class DuplicateBooking
{
    private const PREFIX = 'Cópia ';

    /** Colunas que a cópia não leva, nas duas rotinas. */
    private const CLEARED = [
        'saida_efetiva', 'chegada_efetiva', 'etd_inicial', 'eta_inicial', 'controle_credit', 'controle_debit', 'house_bl',
        'master_bl', 'qtd_bl', 'data_validade', 'ctr_seguro', 'insurance',
    ];

    /** Só na exportação marítima (duplicarExpo). */
    private const CLEARED_MARITIME_EXPORT = ['cotacao_expo_id'];

    /** Só nas outras três (duplicar). */
    private const CLEARED_OTHERS = ['ce_mercante', 'data_ce_mercante', 'cotacao_impo_id', 'flag_prioritario', 'chegada_desejada'];

    public function __construct(
        private ImportExpenseMatrix $matrix,
        private SellerRestriction $sellerRestriction,
        private HistorySnapshot $history,
    ) {}

    /** As reservas do módulo booking, inclusive as canceladas. */
    public static function allows(Embarque $booking, User $user): bool
    {
        return in_array($booking->tipo_embarque, ['m', 'a'], true)
            && in_array($booking->impor_expor, ['i', 'e'], true)
            && (! $user->onlyOperation() || $user->onlyOperation() === $booking->impor_expor);
    }

    public static function asksFreightInvoice(Embarque $booking): bool
    {
        return $booking->impor_expor === 'e' && $booking->tipo_embarque === 'm';
    }

    /**
     * @param  array{flag_fi?: int|string|null, pessoa_id_pagador?: int|string|null, recebedor_freight_invoice?: ?string}  $freightInvoice
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, User $user, array $freightInvoice = []): Embarque
    {
        throw_unless(self::allows($booking, $user), DomainException::class, 'Esta reserva não pode ser duplicada.');
        $freight = $this->freightInvoice($booking, $freightInvoice);

        return DB::transaction(function () use ($booking, $user, $freight) {
            $userId = $user->getKey();
            $original = $booking->fresh();

            $copy = (new Embarque)->forceFill([...$this->attributes($original, $userId), ...$freight]);
            $copy->save();
            $copy->refresh();

            $this->copyExpenses($original->getKey(), $copy->getKey(), $userId);
            ($this->matrix)($copy, $userId);
            ($this->sellerRestriction)($copy);
            ($this->history)($copy->getKey(), $userId);

            if ($copy->cotacao_expo_id) {
                // CotacaoExpo::syncStatusFromEmbarques: cotação com reserva fica "Associada".
                DB::table('cotacao_expo')->where('cotacao_expo_id', $copy->cotacao_expo_id)->update(['status' => 1]);
            }

            return $copy;
        });
    }

    /**
     * Pagador e recebedor só vêm do que foi respondido agora; fora da exportação marítima a cópia fica sem Freight Invoice.
     *
     * @return array{flag_fi: int, pessoa_id_pagador: ?int, recebedor_freight_invoice: ?string}
     *
     * @throws DomainException
     */
    private function freightInvoice(Embarque $booking, array $answers): array
    {
        $none = ['flag_fi' => 0, 'pessoa_id_pagador' => null, 'recebedor_freight_invoice' => null];

        if (! self::asksFreightInvoice($booking)) {
            return $none;
        }

        throw_if(blank($answers['flag_fi'] ?? null), DomainException::class, 'É necessário selecionar alguma opção no campo Freight Invoice.');

        if ((int) $answers['flag_fi'] !== 1) {
            return $none;
        }

        throw_if(blank($answers['pessoa_id_pagador'] ?? null), DomainException::class, 'É necessário selecionar o Pagador.');
        throw_if(blank($answers['recebedor_freight_invoice'] ?? null), DomainException::class, 'É necessário selecionar o Recebedor.');

        return ['flag_fi' => 1, 'pessoa_id_pagador' => (int) $answers['pessoa_id_pagador'], 'recebedor_freight_invoice' => $answers['recebedor_freight_invoice']];
    }

    /** @return array<string, mixed> */
    private function attributes(Embarque $original, int $userId): array
    {
        $cleared = [...self::CLEARED, ...(self::asksFreightInvoice($original) ? self::CLEARED_MARITIME_EXPORT : self::CLEARED_OTHERS)];
        $country = (int) DB::table('porto')->where('porto_id', $original->porto_destino_id)->value('ref_pais');
        $ams = collect(AmsDestination::cases())
            ->mapWithKeys(fn (AmsDestination $destination) => [
                $destination->statusColumn() => (int) ($original->impor_expor === 'e' && $destination->country() === $country),
            ])
            ->all();

        return [
            // O banco antigo tem datas zeradas, que o MySQL estrito não aceita de volta.
            ...array_map(
                fn (mixed $value) => is_string($value) && str_starts_with($value, '0000-00-00') ? null : $value,
                Arr::except($original->getAttributes(), [$original->getKeyName()]),
            ),
            ...array_fill_keys($cleared, null),
            ...$ams,
            'booking' => mb_substr(self::PREFIX.$original->booking, 0, 255),
            'pessoa_id_representante' => $this->isAgent($original->pessoa_id_representante) ? $original->pessoa_id_representante : null,
            'intIdUser' => $userId,
            'dteCadastro' => today()->toDateString(),
            'finalizado' => 'n',
            'ctr_desp' => '1',
            'ctr_pgto' => '1',
            'ctr_pgto_outros' => '1',
        ];
    }

    /** Pessoa::verificaAgente (legado): ainda é agente e atende algum país sem restrição. */
    private function isAgent(?int $personId): bool
    {
        return $personId && DB::table('pessoa as p')
            ->where('p.pessoa_id', $personId)
            ->whereExists(fn (Builder $q) => $q->from('pessoa_tipo_pessoa as t')->whereColumn('t.pessoa_id', 'p.pessoa_id')->where('t.tipo_pessoa_id', 1))
            ->whereExists(fn (Builder $q) => $q->from('agente_pais as a')->whereColumn('a.agente_id', 'p.pessoa_id')->where('a.restricao', 0))
            ->exists();
    }

    /** Todas as despesas, sem o vínculo com BL e container e como lançadas agora. */
    private function copyExpenses(int $from, int $to, int $userId): void
    {
        foreach (DB::table('fin_expenses_values')->where('ref_embarque', $from)->orderBy('id')->get() as $expense) {
            DB::table('fin_expenses_values')->insert([
                ...Arr::except((array) $expense, ['id']),
                'ref_embarque' => $to,
                'ref_bl' => null,
                'ref_container' => null,
                'created_by' => $userId,
                'created_at' => today()->toDateString(),
                'updated_by' => null,
                'updated_at' => null,
            ]);
        }
    }
}

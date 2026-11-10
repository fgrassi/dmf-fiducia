<?php

namespace App\Actions\Courier;

use App\Models\ControleCourier;
use App\Models\FinDespesasVariadas;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * incluirDespesaAgente (legado): lança para o agente da primeira reserva do courier uma nota de débito de metade do valor,
 * em dólar, com a nota do courier como invoice, e trava o courier (`status_vinculo_nota = 's'`).
 *
 * Booking e HBL da nota juntam os das reservas (booking com "/", HBL com " / "), como no legado, cortados em 255 caracteres.
 *
 * Diferenças do legado, que a tela só chamava com reserva ligada e seguia calado nos outros casos: aqui recusa com aviso
 * o courier sem nota, sem reserva, sem valor ou cuja primeira reserva não tem agente (o legado gravaria a despesa sem
 * agente ou com valor zero); e a nota já lançada em outra despesa recusa em vez de não fazer nada.
 *
 * @throws DomainException
 */
class CreateAgentExpense
{
    /** O legado grava a moeda 2 (dólar) direto no código. */
    public const USD_CURRENCY_ID = 2;

    /** varchar(255) de booking e HBL: o legado, sem modo estrito, cortava o texto das reservas que passavam disso. */
    private const TEXT_LIMIT = 255;

    public function __invoke(ControleCourier $courier): FinDespesasVariadas
    {
        throw_if($courier->isLinked(), DomainException::class, 'A despesa do agente deste courier já foi lançada.');
        throw_unless(filled($courier->nota), DomainException::class, 'Informe a nota do courier antes de lançar a despesa.');
        throw_unless((float) $courier->valor > 0, DomainException::class, 'Informe o valor do courier antes de lançar a despesa.');

        $bookings = $courier->bookings()->with('booking')->orderBy('ctr_courier_embarque_id')->get()->pluck('booking')->filter();

        throw_if($bookings->isEmpty(), DomainException::class, 'Ligue o courier a uma reserva antes de lançar a despesa.');
        throw_unless($bookings->first()->pessoa_id_representante, DomainException::class, 'A primeira reserva do courier não tem agente.');

        return DB::transaction(function () use ($courier, $bookings) {
            throw_if(FinDespesasVariadas::where('invoice', $courier->nota)->exists(), DomainException::class, 'Esta nota já foi lançada nas despesas variadas.');

            $expense = FinDespesasVariadas::create([
                'agente_id' => $bookings->first()->pessoa_id_representante,
                'data_despesa' => now()->toDateString(),
                'impor_expor' => 'i',
                'tipo_nota' => '2',
                'booking' => mb_substr($bookings->pluck('booking')->implode('/'), 0, self::TEXT_LIMIT),
                'hbl' => mb_substr($bookings->pluck('house_bl')->implode(' / '), 0, self::TEXT_LIMIT),
                'invoice' => $courier->nota,
                'shipper' => 'COURRIER',
                'cmn_currencies_id' => self::USD_CURRENCY_ID,
                'valor' => $courier->valor / 2,
                'status_pagamento' => 0,
                'status_enviado' => 0,
            ]);

            $courier->update(['status_vinculo_nota' => ControleCourier::LINKED]);

            return $expense;
        });
    }
}

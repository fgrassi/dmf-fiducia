<?php

namespace App\Enums;

/** Nota da reserva: débito (DN) ou crédito (CN/IN), com as tabelas e rótulos que o legado usa para cada uma. */
enum NoteType: string
{
    case Debit = 'd';
    case Credit = 'c';

    public function table(): string
    {
        return $this === self::Debit ? 'fin_debit_notes' : 'fin_credit_notes';
    }

    public function itemsTable(): string
    {
        return $this === self::Debit ? 'fin_debit_note_items' : 'fin_credit_note_items';
    }

    public function itemColumn(): string
    {
        return $this === self::Debit ? 'ref_debit_note' : 'ref_credit_note';
    }

    /** Rótulo do rastreamento ("Cadastro: Debit Note"). */
    public function label(): string
    {
        return $this === self::Debit ? 'Debit Note' : 'Credit Note';
    }

    public function abbreviation(): string
    {
        return $this === self::Debit ? 'DN' : 'CN';
    }

    /** Coluna da reserva que marca "não precisa de nota" (controle_debit / controle_credit). */
    public function needColumn(): string
    {
        return $this === self::Debit ? 'controle_debit' : 'controle_credit';
    }

    /** Evento do rastreamento da troca de status. */
    public function statusEvent(): string
    {
        return $this === self::Debit ? 'x' : 'y';
    }
}

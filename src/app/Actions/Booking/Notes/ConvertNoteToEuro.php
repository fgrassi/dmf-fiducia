<?php

namespace App\Actions\Booking\Notes;

use App\Enums\NoteType;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * executeConverteDN / executeConverteCN (legado): converte os valores da nota para euro pelo fator informado, troca a moeda
 * para o euro (3), grava a cotação do euro e gera o PDF de novo. Os itens da descrição seguem na moeda original.
 * O legado deixava converter de novo uma nota já em euro (multiplicando outra vez); aqui isso é recusado.
 */
class ConvertNoteToEuro
{
    public const EURO = 3;

    private const COLUMNS = ['due_to_dmf', 'due_to_dmf_agent', 'due_to_cnee_agent', 'due_to_shipper_agent', 'gross_profit', 'exw_expenses'];

    private const DEBIT_COLUMNS = ['handling', 'origin_comission'];

    public function __construct(private GenerateNotePdf $pdf) {}

    /** @throws DomainException */
    public function __invoke(NoteType $type, int $noteId, float $factor, float $euroRate, int $userId): void
    {
        $note = DB::table($type->table())->find($noteId);

        if ((int) $note->moeda_id === self::EURO) {
            throw new DomainException('A nota já foi convertida para EURO.');
        }

        if ($factor <= 0 || $euroRate <= 0) {
            throw new DomainException('A cotação e o fator de conversão precisam ser maiores que zero.');
        }

        $columns = [...self::COLUMNS, ...($type === NoteType::Debit ? self::DEBIT_COLUMNS : [])];

        DB::table($type->table())->where('id', $noteId)->update([
            'fator_conversao' => $factor,
            'moeda_id' => self::EURO,
            'dolar_rate' => $euroRate,
            ...collect($columns)->mapWithKeys(fn (string $column) => [$column => $note->{$column} * $factor])->all(),
        ]);

        ($this->pdf)($type, $noteId, $userId);
    }
}

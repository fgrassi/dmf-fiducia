<?php

namespace App\Actions\Booking;

use Illuminate\Support\Facades\DB;

/** DmfUtil::geraLog e geraRastreabilidadeAlteracao (legado): o que a reserva grava no rastreamento e no histórico de campos. */
class BookingLog
{
    public function track(int $bookingId, string $subject, ?string $history, int $userId, ?string $event = null): void
    {
        DB::table('rastreamento')->insert([
            'assunto' => $subject,
            'historico' => $history,
            'ref_embarque' => $bookingId,
            'evento' => $event,
            'created_at' => now()->toDateString(),
            'created_time' => now()->format('H:i:s'),
            'created_by' => $userId,
        ]);
    }

    public function fieldChange(string $table, string $field, int $recordId, ?string $old, ?string $new, int $userId): void
    {
        DB::table('rastreabilidade_alteracao')->insert([
            'tabela' => $table,
            'campo' => $field,
            'tabela_id' => $recordId,
            'valor_antigo' => $old,
            'valor_novo' => $new,
            'created_by' => $userId,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}

<?php

namespace App\Actions\Booking;

use App\Enums\Alert;
use App\Models\Embarque;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * cancelarEmbarque, suspenderEmbarque e removerPendencia (legado): troca o `finalizado` da reserva e registra a
 * justificativa no rastreamento. Cancelar uma exportação com seguro cobrado avisa a exportação por e-mail.
 */
class ChangeStatus
{
    public const OPEN = 'n';

    public const SUSPENDED = 'p';

    public const CANCELLED = 'c';

    /** Seguro (despesas 79, 404 e 595), como Embarque::temSeguroAdicionado. */
    private const INSURANCE_EXPENSES = [79, 404, 595];

    public function __construct(private BookingLog $log) {}

    /** @throws DomainException */
    public function __invoke(Embarque $booking, string $status, string $justification, int $userId): void
    {
        $this->ensureAllowed($booking, $status);

        DB::transaction(function () use ($booking, $status, $justification, $userId) {
            $booking->forceFill(['finalizado' => $status])->save();

            $this->log->track($booking->getKey(), match ($status) {
                self::CANCELLED => 'Embarque Cancelado',
                self::SUSPENDED => 'Embarque Suspenso',
                self::OPEN => 'Pendencia de Embarque Removida',
            }, $justification, $userId, $status === self::CANCELLED ? 'c' : null);
        });

        if ($status === self::CANCELLED && $booking->impor_expor === 'e' && $this->hasInsurance($booking)) {
            $this->warnExport($booking, $justification);
        }
    }

    /** @throws DomainException */
    private function ensureAllowed(Embarque $booking, string $status): void
    {
        throw_if($booking->finalizado === self::CANCELLED, DomainException::class, 'A reserva já está cancelada.');
        throw_if($status === self::SUSPENDED && ! ($booking->impor_expor === 'e' && $booking->tipo_embarque === 'm'), DomainException::class, 'Só é possível suspender uma exportação marítima.');
        throw_if($status === self::OPEN && $booking->finalizado !== self::SUSPENDED, DomainException::class, 'Só é possível reativar uma reserva suspensa.');
    }

    private function hasInsurance(Embarque $booking): bool
    {
        return $booking->expenses()->whereIn('ref_expense', self::INSURANCE_EXPENSES)->where('selling_rate', '>', 0)->exists();
    }

    private function warnExport(Embarque $booking, string $justification): void
    {
        $html = 'A reserva '.e($booking->booking).' foi cancelada, gentileza verificar, pois existe seguro adicionado.'
            .'<br/><br/>Segue a justificativa do cancelamento:<br/>'.nl2br(e($justification));

        Alert::InsuredBookingCancelled->send('AVISO! Cancelamento de Reserva com Seguro', $html, fn ($mail) => $mail->from('system@dmflog.com.br', 'DMF Logistics'));
    }
}

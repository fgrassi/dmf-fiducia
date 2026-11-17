<?php

namespace App\Actions\Booking\Bls;

use App\Models\Container;
use App\Models\Embarque;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** TemperaturaReeferNotification (legado): a temperatura do BL tem que bater com a da reserva nos containers reefer. */
class ReeferTemperature
{
    /** verificaDivergenciaContainer: o container reefer recém-salvo diverge da mercadoria da reserva de mesmo tipo? */
    public function diverges(Container $container, int $bookingId): bool
    {
        if (! Container::isReefer($container->tipo_container_id)) {
            return false;
        }

        $booking = DB::table('mercadoria')
            ->where('embarque_id', $bookingId)
            ->where('tipo_container_id', $container->tipo_container_id)
            ->pluck('temperatura')
            ->first(fn ($temperature) => $temperature !== null && $temperature !== '');

        $bl = $container->temperatura !== null && $container->temperatura !== '' ? (int) $container->temperatura : null;

        return $bl !== ($booking === null ? null : (int) $booking);
    }

    /**
     * getDivergencias: por tipo reefer, as temperaturas da reserva e do BL (ordenadas) que não batem.
     *
     * @return array<int, array{booking: list<int>, bl: list<int>}>
     */
    public function divergences(Embarque $booking): array
    {
        $byType = fn ($rows) => collect($rows)
            ->filter(fn ($row) => Container::isReefer($row->tipo_container_id) && $row->temperatura !== null && $row->temperatura !== '')
            ->groupBy(fn ($row) => (int) $row->tipo_container_id)
            ->map(fn ($group) => $group->map(fn ($row) => (int) $row->temperatura)->sort()->values()->all());

        $fromBooking = $byType(DB::table('mercadoria')->where('embarque_id', $booking->getKey())->get());
        $fromBl = $byType(DB::table('container as c')->join('bl as b', 'b.bl_id', '=', 'c.bl_id')->where('b.embarque_id', $booking->getKey())->get(['c.tipo_container_id', 'c.temperatura']));

        return collect(Container::REEFER_TYPES)
            ->mapWithKeys(fn (int $type) => [$type => ['booking' => $fromBooking[$type] ?? [], 'bl' => $fromBl[$type] ?? []]])
            ->filter(fn (array $temperatures) => ($temperatures['booking'] || $temperatures['bl']) && $temperatures['booking'] !== $temperatures['bl'])
            ->all();
    }

    /** checkAndNotify: só exportação; avisa Expo e Draft por e-mail quando alguma temperatura diverge. */
    public function notify(Embarque $booking): void
    {
        if ($booking->impor_expor !== 'e' || ! ($divergences = $this->divergences($booking))) {
            return;
        }

        $names = DB::table('tipo_container')->whereIn('tipo_container_id', array_keys($divergences))->pluck('nome', 'tipo_container_id');
        $sides = ['booking' => [], 'bl' => []];

        foreach ($divergences as $type => $temperatures) {
            foreach (range(0, max(count($temperatures['booking']), count($temperatures['bl'])) - 1) as $i) {
                $pair = [$temperatures['booking'][$i] ?? null, $temperatures['bl'][$i] ?? null];

                foreach (['booking', 'bl'] as $key => $side) {
                    $sides[$side][] = ['type' => $names[$type] ?? "Tipo de container #{$type}", 'temperature' => $pair[$key], 'diverges' => $pair[0] !== $pair[1]];
                }
            }
        }

        Mail::html(
            '<p>Foi identificada divergência entre a temperatura informada na <strong>reserva</strong> e na <strong>aprovação de BL</strong> para o booking <strong>'.e($booking->booking).'</strong>:</p>'
                .'<table cellpadding="0" cellspacing="0" border="0"><tr>'
                .'<td valign="top" style="padding-right: 16px"><p>Reserva:</p>'.$this->table($sides['booking']).'</td>'
                .'<td valign="top"><p>Aprovação de BL:</p>'.$this->table($sides['bl']).'</td>'
                .'</tr></table><p>Solicitamos a revisão deste booking para validação das informações.</p>',
            fn ($mail) => $mail->to(['exportacao@dmflog.com.br', 'draft@dmflog.com.br'])->subject('[Aviso] Temperatura REEFER divergente - '.$booking->booking),
        );
    }

    /** @param list<array{type: string, temperature: ?int, diverges: bool}> $lines */
    private function table(array $lines): string
    {
        $rows = collect($lines)->map(fn (array $line) => '<tr'.($line['diverges'] ? ' style="color: red"' : '').'><td>'.e($line['type']).'</td><td>'.($line['temperature'] ?? '(não informado)').'</td></tr>')->implode('');

        return '<table cellpadding="4" cellspacing="0" border="1" style="border-collapse: collapse; font-size: 14px; margin-bottom: 8px"><tr style="background-color: #eceef1"><th>equipamento</th><th>temperatura</th></tr>'.$rows.'</table>';
    }
}

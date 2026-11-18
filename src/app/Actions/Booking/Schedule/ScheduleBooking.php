<?php

namespace App\Actions\Booking\Schedule;

use App\Actions\Booking\Tracking\SaveTracking;
use App\Models\Embarque;
use App\Models\Transborda;
use Illuminate\Support\Carbon;

/**
 * updateProgramacaoEmbarque act=2 (legado): grava o cut off da reserva e lança a mensagem "booking programado" (ou
 * "alterado") no rastreamento, em português e inglês, com navio, VG, cut off, ETD e ETA; o rastreamento manda o e-mail.
 * Diferença do legado: não zera a saída efetiva (o legado a gravava em branco, pois a tela nem pedia a data).
 */
class ScheduleBooking
{
    /** Frase "Booking programado" do rastreamento. */
    public const PHRASE = 27;

    /** @var list<int> */
    public array $unsent = [];

    public function __construct(private SaveTracking $save) {}

    /** @param array{altera?: bool, data_reg: string, cutt_off: string, descr?: ?string} $data */
    public function __invoke(Embarque $booking, array $data, int $userId): Transborda
    {
        $booking->forceFill(['cutt_off' => Carbon::parse($data['cutt_off'])->format('Y-m-d H:i:s')])->save();
        $booking->refresh();

        $tracking = ($this->save)($booking, [
            'ref_frase_transborda' => self::PHRASE,
            'descr' => $this->message($booking, (bool) ($data['altera'] ?? false), (string) ($data['descr'] ?? '')),
            'data_reg' => $data['data_reg'],
        ], $userId);
        $this->unsent = $this->save->unsent;

        return $tracking;
    }

    private function message(Embarque $booking, bool $changed, string $note): string
    {
        $block = fn (string $title, array $labels, string $dayMonth) => $title."\n".collect([
            $labels[0] => $booking->navio?->nome,
            'VG' => $booking->vg,
            'Cutt Off' => $this->date($booking->cutt_off, $dayMonth.' H:i'),
            'ETD' => $this->date($booking->saida_prevista, $dayMonth),
            'ETA' => $this->date($booking->chegada_prevista, $dayMonth),
        ])->map(fn ($value, $label) => "- {$label}: {$value}")->implode("\n");

        return implode("\n\n", array_filter([
            $block($changed ? 'BOOKING ALTERADO CONFORME ABAIXO:' : 'BOOKING PROGRAMADO CONFORME ABAIXO:', ['Navio'], 'd/M/Y'),
            $block($changed ? 'BOOKING CHANGED AS PER BELOW:' : 'BOOKING SCHEDULED AS PER BELOW:', ['Vessel'], 'M/d/Y'),
            trim($note),
        ]));
    }

    private function date(mixed $value, string $format): string
    {
        return $value ? Carbon::parse($value)->format($format) : '';
    }
}

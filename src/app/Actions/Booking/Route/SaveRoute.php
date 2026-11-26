<?php

namespace App\Actions\Booking\Route;

use App\Actions\Booking\Tracking\SaveTracking;
use App\Enums\Alert;
use App\Models\Embarque;
use App\Models\EmbarqueRota;
use App\Models\Navio;

/**
 * updateRota (legado): grava a alteração de rota do navio e lança a mensagem "rota alterada" (frase 105) no
 * rastreamento, ligada à rota, que sai por e-mail aos contatos (SaveTracking). Navio de 300 m ou mais avisa a equipe por
 * e-mail. Como no legado, editar uma rota lança outra mensagem.
 */
class SaveRoute
{
    /** Frase "Alteração de rota" do rastreamento. */
    public const PHRASE = 105;

    private const LARGE_SHIP_METERS = 300;

    /** @var list<int> */
    public array $unsent = [];

    public function __construct(private SaveTracking $save) {}

    /** @param array{navio_id: int|string, porto_origem_id: int|string, porto_destino_id: int|string, saida_prevista: string, chegada_prevista: string, vg?: ?string, descr?: ?string, data_reg?: ?string} $data */
    public function __invoke(Embarque $booking, array $data, int $userId, ?EmbarqueRota $route = null): EmbarqueRota
    {
        $attributes = $data + ['embarque_id' => $booking->getKey(), 'created_by' => $userId, 'created_at' => today()->toDateString()];
        $route = $route ? tap($route)->update($attributes) : EmbarqueRota::create($attributes);
        $route->load(['navio', 'portoOrigem', 'portoDestino']);

        $tracking = ($this->save)($booking, [
            'ref_frase_transborda' => self::PHRASE,
            'descr' => $this->message($route),
            'data_reg' => $data['data_reg'] ?? today()->toDateString(),
        ], $userId);
        $tracking->update(['rota_id' => $route->getKey()]);
        $this->unsent = $this->save->unsent;

        if ((float) $route->navio?->tamanho >= self::LARGE_SHIP_METERS) {
            $this->warnLargeShip($booking, $route);
        }

        return $route;
    }

    private function message(EmbarqueRota $route): string
    {
        return implode("\n", [
            'ROTA ALTERADA CONFORME ABAIXO:',
            "- Navio: {$route->navio?->nome}",
            "- Porto de Origem: {$route->portoOrigem?->nome}",
            "- Porto de Destino: {$route->portoDestino?->nome}",
            "- Saida Prevista: {$route->saida_prevista->format('d/m/Y')}",
            "- Chegada Prevista: {$route->chegada_prevista->format('d/m/Y')}",
            "- VG: {$route->vg}",
            '',
            trim((string) $route->descr),
        ]);
    }

    private function warnLargeShip(Embarque $booking, EmbarqueRota $route): void
    {
        $row = fn (string $label, ?string $value) => '<tr><th align="left">'.e($label).'</th><td>'.e((string) $value).'</td></tr>';
        $html = '<h3>O navio selecionado possui mais de 300 metros de comprimento.</h3><table border="1" cellpadding="4">'
            .$row('Navio', $route->navio->nome)
            .$row('Tamanho', $route->navio->tamanho.'m')
            .$row('Porto de Destino', $route->portoDestino?->nome)
            .$row('Saída Prevista', $route->saida_prevista->format('d/m/Y'))
            .$row('Chegada Prevista', $route->chegada_prevista->format('d/m/Y'))
            .$row('Armador', $booking->armador?->nome)
            .$row('Cliente', $booking->cliente?->nome)
            .$row('Booking', $booking->booking)
            .'</table>';

        Alert::LargeShip->send('[Aviso] Navio com mais de 300 metros de comprimento', $html, fn ($mail) => $mail->from('system@dmflog.com.br', 'DMF Logistics'));
    }
}

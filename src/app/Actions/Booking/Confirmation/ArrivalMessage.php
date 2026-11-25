<?php

namespace App\Actions\Booking\Confirmation;

use App\Models\Embarque;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Textos da confirmação de chegada da importação marítima (updateConfirmacaoChegada, legado), em português e inglês, com o
 * free time demurrage da reserva. Diferença do legado: parágrafos separados por linha em branco em vez de `<br\><br\>`.
 */
class ArrivalMessage
{
    public const ARRIVAL = 0;

    public const BERTHING = 1;

    public const DISCHARGE = 2;

    public const UNSTUFFING = 3;

    public const TRANSIT_END = 4;

    public const KINDS = [
        self::ARRIVAL => 'Confirmação de Chegada',
        self::BERTHING => 'Atracação de Navio',
        self::DISCHARGE => 'Confirmação de Descarga',
        self::UNSTUFFING => 'Desova de Container',
        self::TRANSIT_END => 'Conclusão de Trânsito',
    ];

    /** Frase de rastreamento de cada tipo (categoria 11). */
    public const PHRASES = [self::ARRIVAL => 33, self::BERTHING => 110, self::DISCHARGE => 109, self::UNSTUFFING => 93, self::TRANSIT_END => 113];

    /** @return array{descr: string, phrase: int} */
    public function __invoke(Embarque $booking, int $kind, Carbon $date, string $voyage): array
    {
        $ship = (string) DB::table('navio')->where('navio_id', $booking->navio_id_destino)->value('nome');
        $port = DB::table('porto as p')->leftJoin('paises as c', 'c.paises_id', '=', 'p.ref_pais')->where('p.porto_id', $booking->porto_destino_id)->first(['p.nome', 'c.nome as country']);
        $portName = (string) $port?->nome;
        $portLabel = $port ? "{$port->nome} ({$port->country})" : '';
        $day = $date->format('d/M/Y');
        $time = $date->format('H:i');
        $free = $booking->free_time;
        $containers = $booking->nr_container;

        $descr = match ($kind) {
            self::ARRIVAL => implode("\n\n", [
                'CONFIRMAÇÃO DE CHEGADA:',
                "O navio {$ship} vg. {$voyage} iniciou operação no porto de {$portLabel}, no dia {$day} às {$time} e, na data de hoje, recebemos a confirmação de descarga da(s) unidade(s).",
                "Note que o free time demurrage (not storage) para a devolução dos containers vazios ao armador é de {$free} dias e o prazo começou a contar a partir da atracação (início operação) do navio.",
                '*** Caso a informação de free time demurrage desta mensagem não esteja de acordo com a informada em nossa cotação, será considerada, como correta, a quantidade de dias (free time demurrage) informada na cotação.***',
                '=> O armador NÃO está concedendo desconto/isenção de demurrage. Devolva seu(s) container(s) no prazo para evitar custos extras.',
                '=> O armador ESTÁ cobrando taxas de avaria. Atenção e cuidado no manuseio da(s) unidade(s).',
                'CARGO ARRIVED:',
                "The vessel {$ship} voy. {$voyage} berthed in {$portLabel} port, on {$day} at {$time} and today we received discharge confirmation from SS Line.",
                "Note that free time demurrage (not storage) to return the empty containers at {$portName} is {$free} days and the period started when the vessel berthed {$day}.",
                '***If above free time information is different than our quotation, we will consider as correct, free time demurrage informed in our quotation.***',
                '=> The Carrier is NOT giving discount or waived for demurrage fees. Return your(s) container(s) within the free time in order to avoid extra costs.',
                '=> The carrier is charging container damage charges. Attention and careful at handling of(s) unit(s).',
            ]),
            self::BERTHING => implode("\n\n", [
                'ATRACAÇÃO DE NAVIO',
                "Informamos que o navio {$ship} vg. {$voyage} atracou em {$portLabel}, dia {$day} às {$time}, trazendo o(s) container(s) referente a sua importação.",
                'Nosso sistema enviará os valores referente a este embarque em mensagem separada.',
                'CARGO BERTHED',
                "Note that the vessel {$ship} voy. {$voyage} berthed in {$portName}, port on {$day} at {$time} bringing your container(s).",
                'Our system will be sending the invoice with the local charges in a separate message.',
            ]),
            self::DISCHARGE => implode("\n\n", [
                'CONFIRMAÇÃO DE DESCARGA',
                "Note que o(s) container(s) {$containers} foi (foram) descarregado(s).",
                "E o free time demurrage (not storage) para devolução dos containers vazios ao armador é de {$free} dias e o prazo começou a contar a partir da atracação (início da operação) do navio.",
                '*** Caso a informação de free time demurrage desta mensagem não esteja de acordo com a informada em nossa cotação, será considerada, como correta, a quantidade de dias (free time demurrage) informada na cotação.***',
                '=> O Armador NÃO está concedendo desconto/isenção de demurrage. Devolva seu(s) container(s) no prazo para evitar custos extras.',
                '=> O Armador ESTÁ cobrando taxas de avaria. Atenção e cuidado no manuseio da(s) unidade(s).',
                'CARGO DISCHARGED',
                "Note that the container(s) {$containers} was (were) discharged.",
                "And the free time demurrage (not storage) to return the empty containers is {$free} days and the period started when the vessel berthed.",
                '***If above free time information is different than our quotation, we will consider as correct, free time demurrage informed in our quotation.***',
                '=> The Carrier is NOT giving discount or waived for demurrage fees. Return your(s) container(s) within the free time in order to avoid extra costs.',
                '=> The Carrier is charging container damage charges. Attention and careful at handling of(s) unit(s).',
            ]),
            self::UNSTUFFING => implode("\n\n", [
                'DESOVA DE CONTAINER',
                "O container {$containers} foi desovado no dia {$day}.",
                'CONTAINER UNLOADED',
                "The container {$containers} was unloaded on {$day}.",
            ]),
            self::TRANSIT_END => implode("\n\n", [
                'CONCLUSÃO DE TRANSITO',
                "A carga chegou no destino final do dia  {$day}.",
                'CARGO ARRIVAL',
                "Cargo arrived at final destination on {$day}.",
            ]),
        };

        return ['descr' => $descr, 'phrase' => self::PHRASES[$kind]];
    }
}

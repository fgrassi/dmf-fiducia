<?php

namespace App\Actions\Booking\Tracking;

use App\Models\Embarque;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TransbordaEmail::setCorpoTransborda / setCorpoTransbordaTxt (legado): assunto e corpo (HTML ou texto) do e-mail de
 * rastreamento, sempre em inglês. O HTML traz os dados da reserva e a mensagem; se o cliente quer o histórico completo
 * (pessoa.histCompleto diferente de "false"), todas as mensagens da reserva. Diferença do legado: o texto da mensagem
 * sai escapado (HTML digitado à mão deixa de valer), só os destaques de ETA/ETD e o aviso de NOR são HTML.
 */
class BuildTrackingMessage
{
    private const HIGHLIGHTED = ['ETA Transshipment Port', 'ETA Port of Discharge', 'ETA Final Destination', 'ETD Transshipment Port', 'ETD Port of Discharge'];

    /** Frases cujo nome também vai no assunto. */
    private const SUBJECT_PHRASES = ['*** IMPORTANTE – ATUALIZAÇÃO DE DEADLINES ***'];

    /**
     * @param  string  $description  texto da mensagem (cru)
     * @param  string  $noticeHtml  aviso em HTML anexado ao fim da mensagem, já seguro
     * @return array{subject: string, body: string, html: bool}
     */
    public function __invoke(Embarque $booking, string $description, string $date, string $phrase, bool $plainText = false, string $noticeHtml = ''): array
    {
        $data = $this->data($booking);
        $subject = $this->subject($data, $phrase, $plainText);
        $rows = $this->rows($booking, $data);

        if ($plainText) {
            return ['subject' => $subject, 'body' => $this->plainText($data, $rows, $description), 'html' => false];
        }

        return [
            'subject' => $subject,
            'body' => view('mail.booking.tracking', [
                'kind' => ($data->tipo_emb === 'a' ? 'AIR' : 'OCEAN').' '.($data->tipo === 'i' ? 'IMPORT' : 'EXPORT'),
                'logo' => config('services.tracking.url').'/images/email/logo_head.png',
                'rows' => $rows,
                'goods' => $this->goods($booking),
                'import' => $data->tipo === 'i',
                'history' => $this->history($booking, $date, $description, $noticeHtml),
            ])->render(),
            'html' => true,
        ];
    }

    private function data(Embarque $booking): object
    {
        return DB::table('embarque as e')
            ->leftJoin('pessoa as exp', 'exp.pessoa_id', '=', 'e.pessoa_id_cliente')
            ->leftJoin('pessoa as imp', 'imp.pessoa_id', '=', 'e.pessoa_id_importador')
            ->leftJoin('porto as d', 'd.porto_id', '=', 'e.porto_destino_id')
            ->leftJoin('porto as o', 'o.porto_id', '=', 'e.porto_origem_id')
            ->leftJoin('navio as n', 'n.navio_id', '=', 'e.navio_id')
            ->leftJoin('armador as a', 'a.armador_id', '=', 'e.armador_id')
            ->leftJoin('destino_final as fd', 'fd.destino_final_id', '=', 'e.destino_final_id')
            ->leftJoin('paises as pai', 'pai.paises_id', '=', 'fd.pais_id')
            ->leftJoin('cidades as cid', 'cid.cidades_id', '=', 'fd.cidade_id')
            ->where('e.embarque_id', $booking->getKey())
            ->first([
                'e.booking', 'e.customer_ref', 'e.free_time', 'e.obs_free_time_destino', 'e.via', 'e.invoice', 'e.house_bl', 'e.master_bl', 'e.nr_container',
                'e.impor_expor as tipo', 'e.tipo_embarque as tipo_emb', 'e.saida_prevista as etd', 'e.chegada_prevista as eta', 'e.saida_efetiva as etd2', 'e.chegada_efetiva as eta2',
                'exp.nome as exportador', 'imp.nome as importador', 'd.nome as destino', 'o.nome as origem', 'n.nome as navio', 'a.nome as armador',
                'cid.nome as cidade_destino_final', 'pai.nome as pais_destino_final',
            ]);
    }

    private function subject(object $data, string $phrase, bool $plainText): string
    {
        $parts = [
            $data->exportador,
            $plainText ? null : $data->importador,
            $data->invoice,
            $data->customer_ref,
            $data->booking,
        ];
        $reference = collect($parts)->filter(fn ($part) => filled($part))->map(fn ($part) => html_entity_decode((string) $part, ENT_NOQUOTES))->implode(' / ');
        $prefix = in_array(html_entity_decode($phrase, ENT_NOQUOTES), self::SUBJECT_PHRASES, true) ? html_entity_decode($phrase, ENT_NOQUOTES).' / ' : '';

        return mb_substr("DMF Tracking: {$prefix}{$reference}", 0, 255);
    }

    /**
     * As linhas do quadro da reserva, na ordem e com as mesmas regras de preenchimento do legado.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rows(Embarque $booking, object $data): array
    {
        $import = $data->tipo === 'i';
        $days = fn ($value) => trim((string) $value) !== '' ? "{$value} days *" : '';
        $date = fn ($value) => filled($value) && ! str_starts_with((string) $value, '0000-00-00') ? date('d/M/Y', strtotime($value)) : '';
        $lcl = $import && DB::table('mercadoria')->where('embarque_id', $booking->getKey())->where('tipo_container_id', 20)->exists();

        $rows = [
            ['Shipper', $data->exportador],
            ['Cnee', $data->importador],
            ...($import
                ? [['Free Time Demurrage', $days($data->free_time)]]
                : [['Free Time Destination', $days($data->free_time)], ['Obs Free Time Destination', $data->obs_free_time_destino]]),
            ['Booking', $data->booking],
            ['BL', $data->house_bl],
            ['BL Master', $data->master_bl],
            ["Shipper's Reference", $data->invoice],
            ['Port of Loading', $data->origem],
            ['Port of Discharge', $data->destino],
            ['Final Destination', filled($data->cidade_destino_final) ? "{$data->pais_destino_final}, {$data->cidade_destino_final}" : ''],
            ['Via', $data->via],
            ['SS Line', $lcl ? '' : $data->armador],
            ...($import
                ? [['Estimated Departure (ETD)', $date($data->etd)], ['Estimated Arrival (ETA)', $date($data->eta)], ['Confirmed Departure', $date($data->etd2)], ['Confirmed Arrival', $date($data->eta2)]]
                : [filled($data->etd2) ? ['Confirmed Departure', $date($data->etd2)] : ['Estimated Departure (ETD)', $date($data->etd)], ['Confirmed Arrival', $date($data->eta2)]]),
            ['Customer Reference', $data->customer_ref],
            ['Vessel', $data->navio],
            ['Containers', $data->nr_container],
        ];

        $filled = fn (array $row) => filled($row[1]) || in_array($row[0], $import ? ['Free Time Demurrage', 'Booking'] : ['Free Time Destination', 'Obs Free Time Destination', 'Booking'], true);

        return array_values(array_filter(array_map(fn (array $row) => [$row[0], (string) $row[1]], $rows), $filled));
    }

    /** @return Collection<int, object{mercadoria: ?string, quantidade: ?string, equipamento: ?string}> */
    private function goods(Embarque $booking): Collection
    {
        return DB::table('mercadoria as m')
            ->leftJoin('tipo_container as t', 't.tipo_container_id', '=', 'm.tipo_container_id')
            ->where('m.embarque_id', $booking->getKey())
            ->get(['m.nome as mercadoria', 'm.quantidade', 't.nome as equipamento']);
    }

    /**
     * Seção "Eventos/Events": só a mensagem atual ou, se o cliente pede o histórico completo, todas as da reserva.
     *
     * @return list<array{date: string, text: string}>
     */
    private function history(Embarque $booking, string $date, string $description, string $noticeHtml): array
    {
        $full = DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('histCompleto') !== 'false';
        $format = function (string $text): string {
            $text = e($text);
            foreach (self::HIGHLIGHTED as $phrase) {
                $text = str_replace(e($phrase), '<font style="background-color: yellow">'.e($phrase).'</font>', $text);
            }

            return nl2br($text);
        };

        if (! $full) {
            return [['date' => $date, 'text' => $format($description).$noticeHtml]];
        }

        return DB::table('transborda')->where('embarque_id', $booking->getKey())->orderByDesc('transborda_id')->get(['descr', 'data_reg'])
            ->map(fn ($row) => ['date' => date('d/M/Y', strtotime($row->data_reg)), 'text' => $format((string) $row->descr)])
            ->all();
    }

    /** @param list<array{0: string, 1: string}> $rows */
    private function plainText(object $data, array $rows, string $description): string
    {
        $line = str_repeat('*', 66);
        $fields = collect($rows)->map(fn (array $row) => "{$row[0]}: {$row[1]}")->implode("\n");

        return "{$line}\nDMF Logistics do Brasil\n\nTracking System: Plain Text Version\n\n{$line}\n{$fields}\n\nStatus:\n{$description}\n\n{$line}\n\nFor more informations, please contact us at dmf@dmflog.com.br\nDMF Logistics do Brasil - www.dmflog.com.br\n";
    }
}

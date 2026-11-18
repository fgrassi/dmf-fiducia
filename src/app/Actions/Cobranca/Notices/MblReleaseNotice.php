<?php

namespace App\Actions\Cobranca\Notices;

use App\Enums\Notice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoAgenteFaltaPagamento (legado): exportações marítimas não canceladas, com Incoterm diferente de 1, cujo IC de frete
 * (ref_type 1) segue em aberto e que chegam nos próximos 5 dias. Um e-mail em inglês por reserva avisa os contatos do agente
 * (operação exportação ou ambas, do porto de destino ou de qualquer porto) de que a liberação do MBL só sai com o pagamento
 * dos local charges, com cópia ao financeiro. Cliente com prazo ou boleto de exportação não recebe.
 *
 * O legado rotula a data como "ETD", mas imprime a chegada prevista; o texto foi mantido.
 */
class MblReleaseNotice
{
    private const FINANCE = 'financeiro@dmflog.com.br';

    /** @return int reservas avisadas */
    public function __invoke(): int
    {
        if (! Notice::MblRelease->enabled()) {
            return 0;
        }

        $bookings = DB::table('embarque as e')
            ->join('fin_charges as fc', 'fc.ref_booking', '=', 'e.embarque_id')
            ->leftJoin('pessoa as imp', 'imp.pessoa_id', '=', 'e.pessoa_id_importador')
            ->leftJoin('porto as pd', 'pd.porto_id', '=', 'e.porto_destino_id')
            ->leftJoin('paises as pa', 'pa.paises_id', '=', 'pd.ref_pais')
            ->where('e.tipo_embarque', 'm')
            ->where('e.finalizado', '<>', 'c')
            ->where('e.impor_expor', 'e')
            ->where('fc.status', 0)
            ->where('fc.ref_type', 1)
            ->where(fn ($q) => $q->whereNull('e.incoterm_id')->orWhere('e.incoterm_id', '<>', 1))
            ->whereBetween('e.chegada_prevista', [today()->toDateString(), today()->addDays(5)->toDateString()])
            ->orderBy('e.embarque_id')
            ->get(['e.embarque_id', 'e.booking', 'e.master_bl', 'e.house_bl', 'e.chegada_prevista', 'e.cliente_id', 'e.pessoa_id_representante', 'e.porto_destino_id', 'imp.nome as importador', 'pd.nome as porto', 'pa.nome as pais']);

        $sent = 0;

        foreach ($bookings as $b) {
            if ($this->hasExportBoleto($b->cliente_id)) {
                continue;
            }

            $contacts = DB::table('agente_contato')
                ->where('ref_pessoa', $b->pessoa_id_representante)
                ->whereIn('operacao', ['e', 'a'])
                ->where(fn ($q) => $q->whereNull('porto_id')->orWhere('porto_id', 0)->orWhere('porto_id', $b->porto_destino_id))
                ->orderBy('id')
                ->pluck('name', 'email')
                ->all();

            $html = '<p>Dear all</p>'
                .'<p>We would like to inform that DMF didn’t receive local charges payment for booking '.e($b->booking).' . Please note that MBL release will be made by SS Line once payment is confirmed.</p>'
                .'<ul><li>MBL: '.e($b->master_bl).'</li><li>HBL: '.e($b->house_bl).'</li><li>ETD: '.$b->chegada_prevista.'</li>'
                .'<li>POD: '.e($b->porto ? "{$b->porto} ({$b->pais})" : '').'</li></ul>';

            Mail::html($html, fn ($mail) => $mail
                ->to($contacts)
                ->cc(Notice::MblRelease->copies())
                ->replyTo(self::FINANCE)
                ->subject("[IMPORTANT] MBL Release Information: {$b->booking} - MBL: {$b->master_bl} - Cnee: {$b->importador}"));

            $sent++;
        }

        return $sent;
    }

    /** Pessoa::getPrazoBoletoExpo: só olha o primeiro cadastro de boleto do cliente. */
    private function hasExportBoleto(?int $clientId): bool
    {
        $boleto = DB::table('fin_clients_boleto')->where('ref_client', $clientId)->orderBy('id')->first();

        return $boleto && ($boleto->prazo_exp || $boleto->boleto_exp);
    }
}

<?php

namespace App\Actions\Cobranca\Notices;

use App\Actions\Notices\RenderNoticeEmail;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Cobranca\ChargeStatus;
use App\Enums\Notice;
use App\Models\Armador;
use App\Models\FinCharges;
use App\Services\Cobranca\ChargeDetails;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * cron/avisoCobrancaAberta (legado): um e-mail por reserva aos contatos de cobrança com as ICs em aberto. Normais (ref_type 1)
 * todo dia a partir do 7º dia; extras (ref_type 2) só no 7º dia. Marítimo/aéreo, sem clientes com boleto nem as exceções do
 * app.yml do tracking; Soprano (2164) só depois da chegada efetiva.
 */
class OpenChargeReminders
{
    /** app_clientes_excluidos_detention (app.yml do tracking). */
    private const EXCLUDED_CLIENTS = [887, 16640, 3923, 537, 1787, 19822, 10557, 12733, 3935, 15119, 721, 4646, 9060, 7527, 12962, 33215, 13, 1945, 226, 2902, 5992, 10149, 6212, 1949, 13179];

    /** app_reservas_excluidas_detention (app.yml do tracking). */
    private const EXCLUDED_BOOKINGS = [46183, 47206, 46789, 50469, 60143, 67142, 67143];

    private const SOPRANO_CLIENT = 2164;

    public function __construct(private ChargeDetails $details) {}

    /** @return int e-mails enviados */
    public function __invoke(): int
    {
        if (! Notice::OpenCharges->enabled()) {
            return 0;
        }

        $limit = today()->subDays(7);
        $excludedClients = [...self::EXCLUDED_CLIENTS, ...DB::table('fin_clients_boleto')->whereNotNull('ref_client')->pluck('ref_client')->all()];
        $sent = 0;

        foreach ([1 => '<=', 2 => '='] as $type => $operator) {
            $charges = FinCharges::with('embarque', 'bl')
                ->where('status', ChargeStatus::Open)
                ->where('ref_type', $type)
                ->whereDate('created_at', $operator, $limit)
                ->whereHas('embarque', fn ($q) => $q
                    ->whereIn('tipo_embarque', ['m', 'a'])
                    ->where(fn ($q) => $q->where('cliente_id', '<>', self::SOPRANO_CLIENT)->orWhereNotNull('chegada_efetiva'))
                    ->whereNotIn('cliente_id', $excludedClients)
                    ->whereNotIn('embarque_id', self::EXCLUDED_BOOKINGS))
                ->orderBy('id')
                ->get();

            foreach ($charges->groupBy('ref_booking') as $booking) {
                $this->send($booking);
                $sent++;
            }
        }

        return $sent;
    }

    /** @param  Collection<int, FinCharges>  $charges */
    private function send(Collection $charges): void
    {
        $e = $charges->first()->embarque;
        $client = DB::table('pessoa')->where('pessoa_id', $e->cliente_id)->first(['nome', 'cnpj']);
        $armador = Armador::find($e->armador_id);
        // legado: o assunto olha a última IC do loop
        $days = $charges->last()->created_at->diffInDays(today()) == 7 ? 'há 7 dias' : 'há mais de 7 dias';

        $rows = $charges->map(fn (FinCharges $c) => '<tr><td align="center">'.e($c->bl?->bl).'</td>'
            .'<td align="center">'.$c->created_at->format('d/m/Y').'</td>'
            .'<td align="right">R$'.number_format((float) $c->total_value, 2, ',', '.').'</td></tr>')->implode('');

        $list = "<table width='960px' border=1><tr><th>Reserva</th><th>Cliente</th><th>Detalhes da Cobrança</th></tr>"
            .'<tr><td align=center>'.e($e->booking).'</td><td align=center>'.e($client ? "{$client->nome} ({$client->cnpj})" : '').'</td>'
            ."<td><table><tr><th>HBL</th><th>Data da Cobrança</th><th>Valor</th></tr>{$rows}</table></td></table>";

        $lateFee = filled($armador?->late_payment)
            ? '<p><u>NOTAR ABAIXO OS VALORES REFERENTE A TAXA A SER APLICADA PARA ATRASO NOS PAGAMENTOS DE IMPORTAÇÃO / EXPORTAÇÃO:</u></p>'
                .'<p>'.e($armador->nome).'</p>'.$armador->late_payment
                .'<p>Estamos ao seu dispor caso necessite maiores detalhes e agradecemos pelo entendimento e suporte.</p><p>Atenciosamente,<br />Departamento Financeiro</p>'
            : '';

        [$subject, $html] = app(RenderNoticeEmail::class)(Notice::OpenCharges, ['reserva' => $e->booking, 'prazo' => $days, 'lista' => $list, 'taxa_atraso' => $lateFee]);
        $to = collect($this->details->contacts($charges->first()))->pluck('email')->filter()->unique()->values()->all();

        app(SendNoticeMail::class)(
            $subject,
            $html,
            $to,
            bcc: Notice::OpenCharges->copies(),
            from: 'financeiro@dmflog.com.br',
        );
    }
}

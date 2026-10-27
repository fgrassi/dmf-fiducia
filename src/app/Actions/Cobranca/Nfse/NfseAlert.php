<?php

namespace App\Actions\Cobranca\Nfse;

use App\Models\Embarque;
use App\Models\FinCharges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * enviarAlertaNfseGeracaoCobranca (legado): avisa o financeiro (cópia para chamado@) das NFS-e que não saíram
 * na geração/recálculo da IC. Sem número de cobrança, a IC nem foi gerada (tomador inválido).
 *
 * @phpstan-type Row array{charge:?int, booking:string, bl:string, client:string, error:string}
 */
class NfseAlert
{
    private const FINANCE_EMAIL = 'financeiro@dmflog.com.br';

    /** @return Row */
    public static function row(FinCharges $charge, string $error): array
    {
        $bl = $charge->ref_bl ? (string) DB::table('bl')->where('bl_id', $charge->ref_bl)->value('bl') : '';

        return ['charge' => $charge->id] + self::bookingRow($charge->embarque, $bl, $error);
    }

    /** @return Row linha sem IC (a cobrança não foi gerada) */
    public static function bookingRow(Embarque $embarque, string $bl, string $error): array
    {
        return [
            'charge' => null,
            'booking' => (string) $embarque->booking,
            'bl' => $bl,
            'client' => (string) DB::table('pessoa')->where('pessoa_id', $embarque->cliente_id)->value('nome'),
            'error' => $error,
        ];
    }

    /** @param  list<Row>  $rows */
    public function __invoke(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $lines = collect($rows)
            ->map(fn (array $r) => '<tr>'.collect([$r['charge'] ?? '—', $r['booking'], $r['bl'], $r['client'], $r['error']])
                ->map(fn ($v) => '<td>'.e((string) $v).'</td>')
                ->implode('').'</tr>')
            ->implode('');

        Mail::html(
            'As reservas abaixo não tiveram NFSe emitida automaticamente. '
                .'Quando a cobrança está em branco, a IC não foi gerada (CNPJ/CPF do tomador inválido ou ausente) '
                .'e o cron tentará novamente após a correção do Consignee/Shipper do BL. '
                .'Se a IC já existir, corrija os dados e reemita a NFSe na cobrança.<br/><br/>'
                .'<table cellpadding="4" border="1" style="border-collapse:collapse">'
                .'<tr><th>Cobrança</th><th>Booking</th><th>BL</th><th>Cliente</th><th>Erro</th></tr>'
                .$lines.'</table>',
            fn ($mail) => $mail->to(self::FINANCE_EMAIL)->cc('chamado@dmflog.com.br')
                ->subject('DMF Logistics: '.count($rows).' NFSe(s) não emitida(s) na geração da instrução de cobrança'),
        );
    }
}

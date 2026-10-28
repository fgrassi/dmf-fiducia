<?php

namespace App\Actions\InvoiceControls;

use App\Actions\Cobranca\Generation\BookingChecks;
use App\Actions\Cobranca\Generation\GenerateCharges;
use App\Models\Embarque;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * blICobrancaBloqueadaPorInvoice + digest "cobranca_bloqueada_invoice" (Cobranca legado): IC marítima "Normal" de armador
 * que exige invoice só sai com o controle de invoice (controle_invoice_master) do MBL recebido ou liberado.
 * Sem controle, ele é criado pendente. No fim da geração o financeiro recebe os pendentes + os liberados aguardando arquivo.
 */
class InvoiceGate
{
    private const PENDING = 0;

    private const RELEASED_AWAITING_FILE = 2;

    /** @var array<string, array<string, string>> bloqueios desta rodada, por embarque|MBL */
    private array $blocked = [];

    public function blocks(Embarque $b, stdClass $bl, int $chargeType, ?int $userId): bool
    {
        if ($b->tipo_embarque !== 'm' || $chargeType === GenerateCharges::TYPE_EXTRA) {
            return false;
        }

        $carrier = DB::table('armador')->where('armador_id', $b->armador_id)->first();

        if (! $carrier || ! ($b->impor_expor === 'i' ? $carrier->invoice_impo : $carrier->invoice_expo)) {
            return false;
        }

        $mbl = trim((string) $bl->mbl_number);
        $control = DB::table('controle_invoice_master')->where('embarque_id', $b->embarque_id)->where('mbl_number', $mbl)->first();

        if ($control && (int) $control->status !== self::PENDING) {
            return false;
        }

        if (! $control) {
            $id = DB::table('controle_invoice_master')->insertGetId([
                'embarque_id' => $b->embarque_id,
                'armador_id' => $carrier->armador_id,
                'mbl_number' => $mbl,
                'status' => self::PENDING,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
            DB::table('controle_invoice_master_log')->insert([
                'controle_invoice_master_id' => $id,
                'acao' => 'criacao',
                'historico' => 'CIM criado automaticamente ao bloquear a cobrança.',
                'status_novo' => self::PENDING,
                'created_at' => now(),
                'created_by' => $userId,
            ]);
            $control = DB::table('controle_invoice_master')->where('controle_invoice_master_id', $id)->first();
        }

        $this->blocked["{$b->embarque_id}|{$mbl}"] ??= $this->row($b, $carrier->nome, $mbl, 'Invoice pendente', $control->created_at, null);

        return true;
    }

    /** anexarInvoicesAguardandoArquivo + envio do digest; zera os bloqueios da rodada. */
    public function sendDigest(): void
    {
        $rows = $this->blocked;
        $this->blocked = [];

        $released = DB::table('controle_invoice_master')->where('status', self::RELEASED_AWAITING_FILE)->orderBy('controle_invoice_master_id')->get();

        foreach ($released as $control) {
            $b = Embarque::find($control->embarque_id);
            $mbl = trim((string) $control->mbl_number);

            if (! $b || isset($rows["{$b->embarque_id}|{$mbl}"])) {
                continue;
            }

            $releasedAt = DB::table('controle_invoice_master_log')
                ->where('controle_invoice_master_id', $control->controle_invoice_master_id)
                ->where('acao', 'liberacao')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('created_at');
            $carrier = DB::table('armador')->where('armador_id', $control->armador_id)->value('nome');
            $rows["{$b->embarque_id}|{$mbl}"] = $this->row($b, (string) $carrier, $mbl, 'Liberado, aguardando arquivo', $control->created_at, $releasedAt);
        }

        if ($rows === []) {
            return;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => self::legacyOrder($a, $b));

        $lines = collect($rows)
            ->map(fn (array $r) => '<tr>'.collect(['armador', 'reserva', 'cliente', 'mbl', 'created_at', 'etd', 'eta', 'liberado_at', 'situacao'])
                ->map(fn ($k) => '<td>'.e($r[$k]).'</td>')
                ->implode('').'</tr>')
            ->implode('');

        BookingChecks::notify('Controle de Invoice: pendentes e aguardando arquivo',
            '<table cellpadding="4" border="1" style="border-collapse:collapse">'
            .'<tr><th>Armador</th><th>Reserva</th><th>Cliente</th><th>MBL</th><th>Criado em</th><th>ETD</th><th>ETA</th><th>Liberado em</th><th>Situação</th></tr>'
            .$lines.'</table>');
    }

    /** montarItemDigestInvoice. @return array<string, string> */
    private function row(Embarque $b, string $carrier, string $mbl, string $situation, mixed $createdAt, mixed $releasedAt): array
    {
        return [
            'armador' => $carrier,
            'reserva' => (string) $b->booking,
            'cliente' => (string) DB::table('pessoa')->where('pessoa_id', $b->cliente_id)->value('nome'),
            'mbl' => $mbl,
            'situacao' => $situation,
            'created_at' => self::date($createdAt),
            'etd' => self::date($b->saida_prevista),
            'eta' => self::date($b->chegada_prevista),
            'liberado_at' => self::date($releasedAt),
        ];
    }

    /**
     * NotificationDigest::sendViaFiducia (legado): ordena por strtotime do ETA (senão ETD) já formatado em d/m/Y.
     * Como no legado, strtotime lê d/m/Y como m/d/Y (dia > 12 vira false) e o comparador mistura false com inteiros,
     * então a ordem real é quase sempre a de inserção. Mantido igual de propósito.
     *
     * @param  array<string, string>  $a
     * @param  array<string, string>  $b
     */
    private static function legacyOrder(array $a, array $b): int
    {
        $date = fn (array $r) => ($r['eta'] ?? '') ? strtotime($r['eta']) : (($r['etd'] ?? '') ? strtotime($r['etd']) : 0);
        [$da, $db] = [$date($a), $date($b)];

        return $da === $db ? 0 : ($da < $db ? -1 : 1);
    }

    /** ControleInvoiceMasterLogService::formatarData: d/m/Y, com hora quando houver. */
    public static function date(mixed $value): string
    {
        if (blank($value) || str_starts_with((string) $value, '0000-00-00')) {
            return '';
        }

        $date = Carbon::parse($value);

        return $date->format($date->format('H:i:s') === '00:00:00' ? 'd/m/Y' : 'd/m/Y H:i');
    }
}

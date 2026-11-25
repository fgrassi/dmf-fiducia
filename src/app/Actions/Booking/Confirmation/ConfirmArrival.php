<?php

namespace App\Actions\Booking\Confirmation;

use App\Actions\Booking\Tracking\SendTracking;
use App\Models\Embarque;
use App\Models\Transborda;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * updateConfirmacaoChegada act=2 (legado): confirma a chegada da reserva. Importação marítima escolhe o tipo (chegada,
 * atracação, descarga, desova ou conclusão de trânsito) e o texto sai de ArrivalMessage; exportação e aéreo escolhem uma frase
 * da categoria 11 e escrevem o texto. Grava a mensagem, a chegada efetiva (a data e hora informadas) e o navio de destino; na
 * importação finaliza a reserva e acerta free time e retirada do demurrage. Na primeira confirmação cria a
 * `arrival_confirmation`, o controle de invoice (menos exportação) e a mensagem aos favoritos (importação). O e-mail sai pelo
 * SendTracking. Chegada e atracação avisam a documentação se o CE Mercante foi lançado até 72h da data; a chegada avisa o
 * financeiro se as despesas já revisadas divergem da matriz do armador.
 *
 * Bloqueios: data no futuro; VG nos tipos chegada e atracação; confirmação do financeiro (importação marítima de Yang Ming,
 * HMM e PIL); frase e texto na exportação e no aéreo.
 */
class ConfirmArrival
{
    private const FINANCE_CARRIERS = [47, 50, 64];

    /** @var bool false se o e-mail ao cliente não saiu (sem contato ou falha) */
    public bool $sent = true;

    public function __construct(
        private SendTracking $send,
        private ArrivalMessage $message,
        private ExpenseMatrixCheck $matrix,
        private CreateInvoiceControls $invoices,
        private NotifyFavorites $favorites,
    ) {}

    /** Exportação e aéreo escolhem a frase; importação marítima escolhe o tipo. */
    public static function usesPhrase(Embarque $booking): bool
    {
        return $booking->impor_expor === 'e' || $booking->tipo_embarque === 'a';
    }

    /** Importação marítima de um dos armadores que exigem o aval do financeiro. */
    public static function needsFinanceConfirmation(Embarque $booking): bool
    {
        return $booking->impor_expor === 'i' && $booking->tipo_embarque === 'm' && in_array((int) $booking->armador_id, self::FINANCE_CARRIERS, true);
    }

    /**
     * @param  array{data_reg: string, dtePrevista: string, navio_id_destino?: int|string|null, kind?: int|string|null, vg?: ?string, frase?: int|string|null, descr?: ?string, finance_confirmed?: bool}  $data
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $data, int $userId, ?Transborda $record = null): Transborda
    {
        $arrival = Carbon::parse($data['dtePrevista']);
        $kind = self::usesPhrase($booking) ? null : (int) ($data['kind'] ?? -1);
        $this->ensureAllowed($booking, $data, $arrival, $kind);

        $first = ! DB::table('arrival_confirmation')->where('ref_embarque', $booking->getKey())->exists();

        $tracking = DB::transaction(function () use ($booking, $data, $userId, $record, $first, $arrival, $kind) {
            if (filled($data['navio_id_destino'] ?? null)) {
                DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['navio_id_destino' => $data['navio_id_destino']]);
                $booking->refresh();
            }

            ['descr' => $descr, 'phrase' => $phrase] = $kind === null
                ? ['descr' => (string) $data['descr'], 'phrase' => (int) $data['frase']]
                : ($this->message)($booking, $kind, $arrival, (string) ($data['vg'] ?? ''));

            $attributes = [
                'embarque_id' => $booking->getKey(),
                'descr' => $descr,
                'data_reg' => $data['data_reg'],
                'ref_frase_transborda' => $phrase,
                'usuario_id' => $userId,
                'dtePrevista' => $arrival->toDateTimeString(),
            ];
            $tracking = $record ? tap($record)->update($attributes) : Transborda::create($attributes);

            $update = ['chegada_efetiva' => $arrival->toDateTimeString()] + ($booking->impor_expor === 'i' ? ['finalizado' => 's'] : []);
            DB::table('embarque')->where('embarque_id', $booking->getKey())->update($update);

            if ($booking->impor_expor === 'i') {
                DB::table('fin_demurrage')->where('ref_embarque', $booking->getKey())->update(['free_time' => $booking->free_time, 'data_retirada' => $arrival->toDateTimeString()]);
            }

            if ($first) {
                DB::table('arrival_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => $userId, 'created_at' => now()->toDateString()]);

                if ($booking->impor_expor !== 'e') {
                    ($this->invoices)($booking, $userId);
                }

                if ($booking->impor_expor === 'i') {
                    ($this->favorites)($booking, 'Embarque Favorito: Confirmação de Chegada!', "O embarque {$booking->booking} teve a chegada confirmada pelo usuário ".DB::table('usuario')->where('usuario_id', $userId)->value('nome'), $userId);
                }
            }

            return $tracking;
        });

        $booking->refresh();
        $this->sent = ($this->send)($booking, $tracking, $userId);

        if (in_array($kind, [ArrivalMessage::ARRIVAL, ArrivalMessage::BERTHING], true)) {
            $this->warnDeconsolidation($booking, $arrival);
        }

        if ($kind === ArrivalMessage::ARRIVAL && $this->matrix->hasArrivalDivergence($booking, $arrival->toDateTimeString())) {
            Mail::html('<p>Existe inconsistência entre as despesas da reserva e as despesas do cadastro do armador/porto. Verifique!</p>', fn ($mail) => $mail->to('financeiro@dmflog.com.br')->subject("[FINANCEIRO] Alerta de Alterações: {$booking->booking}"));
        }

        return $tracking;
    }

    /** @throws DomainException */
    private function ensureAllowed(Embarque $booking, array $data, Carbon $arrival, ?int $kind): void
    {
        throw_if($arrival->copy()->startOfDay()->isFuture(), DomainException::class, 'A previsão de chegada não pode ser maior que o dia de hoje!');

        if ($kind === null) {
            throw_if(blank($data['frase'] ?? null) || blank($data['descr'] ?? null), DomainException::class, 'Preencha a frase e a descrição.');
        } else {
            throw_unless(array_key_exists($kind, ArrivalMessage::KINDS), DomainException::class, 'Escolha o tipo de confirmação.');
            throw_if(in_array($kind, [ArrivalMessage::ARRIVAL, ArrivalMessage::BERTHING], true) && blank($data['vg'] ?? null), DomainException::class, 'Preencha o campo "VG".');
        }

        throw_if(self::needsFinanceConfirmation($booking) && empty($data['finance_confirmed']), DomainException::class, 'É necessária a confirmação com o setor Financeiro');
    }

    /** DmfUtil::verificaDesconsolidacao (legado): CE Mercante lançado até 72h (para mais ou para menos) da data da atracação. */
    private function warnDeconsolidation(Embarque $booking, Carbon $arrival): void
    {
        if (blank($booking->data_ce_mercante) || $arrival->diffInHours(Carbon::parse($booking->data_ce_mercante), true) > 72) {
            return;
        }

        Mail::html("Gentileza verificar o embarque: {$booking->booking}", fn ($mail) => $mail->to(['diego@dmflog.com.br', 'docimportacao@dmflog.com.br'])->subject('AVISO: Desconsolidação inferior à 3 dias da atracação do navio'));
    }
}

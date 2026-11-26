<?php

namespace App\Actions\Booking\Confirmation;

use App\Actions\Booking\Bls\FinalizeBl;
use App\Actions\Booking\GenerateValueDispatchPendency;
use App\Actions\Booking\Tracking\SendTracking;
use App\Enums\Alert;
use App\Models\Bl;
use App\Models\Embarque;
use App\Models\Porto;
use App\Models\Transborda;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * updateConfirmacaoEmbarque act=2 (legado): confirma a saída da reserva. Grava a mensagem de rastreamento (frase 82, texto
 * em português e inglês com a previsão de chegada somada ao trânsito previsto), a saída efetiva, e na primeira confirmação a
 * `departure_confirmation`, o controle de invoice (menos importação), o aviso de despesas PA (exportação) e a mensagem aos
 * favoritos (exportação). Cria as pendências de free hand, envio de valores e liberação de embarque (importação, com aviso à
 * Importação) e põe o controle PA em "Embarque Confirmado". O e-mail sai pelo SendTracking; na exportação marítima sem BL
 * direto vai a cópia dos BLs aprovados, com cópia para a documentação. Duroline/Stemco avisam o draft e a regra NRA manda os
 * documentos ao agente da AIC.
 *
 * Bloqueios: data efetiva no futuro; validações da Freight Invoice (exportação); confirmação do financeiro (marítima do grupo
 * Faros); NRA sem documentos; compra diferente da matriz do armador (exportação). Diferença do legado: Duroline/Stemco só
 * avisam na primeira confirmação, e o controle PA, o NRA e o aviso só mudam se a confirmação passou (o legado os rodava mesmo
 * quando o bloqueio da matriz recusava).
 */
class ConfirmDeparture
{
    public const PHRASE = 82;

    public const FAROS_GROUP = [12460, 5476, 8509, 4414, 6965, 10761, 13938, 18228, 23727];

    private const DUROLINE = [1892, 3465];

    /** @var bool false se o e-mail ao cliente não saiu (sem contato ou falha) */
    public bool $sent = true;

    public function __construct(
        private SendTracking $send,
        private ExpenseMatrixCheck $matrix,
        private CreateInvoiceControls $invoices,
        private NotifyFavorites $favorites,
        private GenerateValueDispatchPendency $valueDispatch,
        private FinalizeBl $finalizeBl,
        private SendNra $nra,
    ) {}

    /**
     * @param  array{data_reg: string, saida_efetiva: string, finance_confirmed?: bool}  $data
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $data, int $userId, ?Transborda $record = null): Transborda
    {
        $this->ensureAllowed($booking, $data);

        $first = ! DB::table('departure_confirmation')->where('ref_embarque', $booking->getKey())->exists();
        $releaseCreated = false;

        $tracking = DB::transaction(function () use ($booking, $data, $userId, $record, $first, &$releaseCreated) {
            $this->createPendencies($booking);
            $releaseCreated = $this->createRelease($booking);

            $attributes = [
                'embarque_id' => $booking->getKey(),
                'descr' => $this->text($booking, Carbon::parse($data['saida_efetiva'])),
                'data_reg' => $data['data_reg'],
                'ref_frase_transborda' => self::PHRASE,
                'usuario_id' => $userId,
                'dteEmbarque' => $data['saida_efetiva'],
            ];
            $tracking = $record ? tap($record)->update($attributes) : Transborda::create($attributes);

            DB::table('embarque')->where('embarque_id', $booking->getKey())->update(['saida_efetiva' => $data['saida_efetiva']]);
            DB::table('controle_pa')->where('embarque_id', $booking->getKey())->update(['flag_fi_dmf' => 2]);

            if ($first) {
                DB::table('departure_confirmation')->insert(['ref_embarque' => $booking->getKey(), 'created_by' => $userId, 'created_at' => now()->toDateString()]);

                if ($booking->impor_expor !== 'i') {
                    ($this->invoices)($booking, $userId);
                }

                if ($booking->impor_expor === 'e') {
                    ($this->favorites)($booking, 'Embarque Favorito: Confirmação de Embarque!', "O embarque {$booking->booking} teve o embarque confirmado pelo usuário ".DB::table('usuario')->where('usuario_id', $userId)->value('nome'), $userId);
                }
            }

            return $tracking;
        });

        $booking->refresh();

        if ($releaseCreated) {
            Alert::ReleasePending->send('Nova pendência de Liberação de Embarque - '.$booking->booking, 'Gentileza verificar sobre a liberação de embarque - '.$booking->booking);
        }

        if ($first) {
            $this->notifyFirstConfirmation($booking);
        }

        $this->deliver($booking, $tracking, $userId);

        if ($this->nra->shouldSendOnConfirmation($booking)) {
            ($this->nra)($booking, $userId);
        }

        return $tracking;
    }

    /** @throws DomainException */
    private function ensureAllowed(Embarque $booking, array $data): void
    {
        throw_if(Carbon::parse($data['saida_efetiva'])->startOfDay()->isFuture(), DomainException::class, 'A data efetiva não pode ser maior que o dia de hoje!');

        $export = $booking->impor_expor === 'e';

        if ($export) {
            $this->ensureFreightInvoice($booking);

            throw_if(
                $booking->tipo_embarque === 'm' && in_array((int) $booking->cliente_id, self::FAROS_GROUP, true) && empty($data['finance_confirmed']),
                DomainException::class,
                'É necessária a confirmação com o setor Financeiro!',
            );
        }

        throw_if(
            $booking->impor_expor !== 'i' && $this->nra->applies($booking) && $this->nra->files($booking)->isEmpty() && $this->nra->sendCount($booking) === 0,
            DomainException::class,
            'NRA não efetivado: gentileza entregar o processo ao Depto. Comercial de Exportação. É necessário adicionar os docs de NRA para poder confirmar!',
        );

        if ($export) {
            $check = $this->matrix->forDeparture($booking, $data['saida_efetiva']);

            if (! $check['ok']) {
                $names = DB::table('fin_expenses')->whereIn('id', $check['expenses']->pluck('ref_expense'))->pluck('name', 'id');

                throw new DomainException(trim($check['message']."\n".$check['expenses']->map(fn ($row) => '- '.($names[$row->ref_expense] ?? $row->ref_expense).": matriz {$row->expense_value}")->implode("\n")));
            }
        }
    }

    /** verificaFi (legado): a Freight Invoice da reserva precisa estar de acordo com as despesas FI e com o controle PA. */
    private function ensureFreightInvoice(Embarque $booking): void
    {
        $control = DB::table('controle_pa')->where('embarque_id', $booking->getKey())->first();
        $flag = (int) $booking->flag_fi;
        $expenses = DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->where('billing', 'f')->count();

        if (! $control) {
            throw_if($flag === 1, DomainException::class, 'A Freight Invoice ainda não foi gerada, gentileza informar ao setor de Exportação.');

            return;
        }

        if ($booking->tipo_embarque !== 'm') {
            return;
        }

        throw_if($expenses > 0 && $flag === 0, DomainException::class, 'Existem despesas do tipo FI (Freight Invoice) na reserva, mas a reserva tem o campo Freight Invoice como Não, gentileza informar ao setor de Exportação.');
        throw_if($expenses === 0 && $flag === 1, DomainException::class, 'Não existem despesas do tipo FI (Freight Invoice) na reserva, mas a reserva tem o campo Freight Invoice como Sim, gentileza informar ao setor de Exportação.');
        throw_if($flag === 1 && (int) $control->flag_fi_dmf === 1, DomainException::class, 'A Freight Invoice ainda não foi gerada, gentileza informar ao setor de Exportação.');
    }

    private function createPendencies(Embarque $booking): void
    {
        if ($booking->loading_type === 'f' && ! DB::table('controle_free_hand')->where('embarque_id', $booking->getKey())->exists()) {
            DB::table('controle_free_hand')->insert(['embarque_id' => $booking->getKey(), 'status' => 0]);
        }

        ($this->valueDispatch)($booking->getKey());
    }

    /** Importação: cliente, consignee ou exportador com liberação de embarque controlada. */
    private function createRelease(Embarque $booking): bool
    {
        if ($booking->impor_expor !== 'i' || DB::table('liberacao_embarque')->where('embarque_id', $booking->getKey())->exists()) {
            return false;
        }

        $controlled = DB::table('pessoa')->whereIn('pessoa_id', array_filter([$booking->cliente_id, $booking->pessoa_id_importador, $booking->pessoa_id_cliente]))->where('ctr_liberacao', 's')->exists();

        return $controlled && DB::table('liberacao_embarque')->insert(['embarque_id' => $booking->getKey(), 'status' => 0]);
    }

    private function text(Embarque $booking, Carbon $date): string
    {
        if ($booking->tipo_embarque === 'a') {
            return 'Flight confirmed';
        }

        $transit = $booking->saida_prevista && $booking->chegada_prevista ? Carbon::parse($booking->saida_prevista)->diffInDays(Carbon::parse($booking->chegada_prevista), true) : 0;
        $arrival = $date->copy()->addDays((int) $transit);
        $ship = (string) $booking->navio?->nome;
        $origin = (string) Porto::find($booking->porto_origem_id)?->label;
        $destination = (string) Porto::find($booking->porto_destino_id)?->label;

        return "CONFIRMAÇÃO DE EMBARQUE: Container(s) embarcado(s) no navio {$ship} vg.{$booking->vg} no dia {$date->format('d/M/Y')} em {$origin}."
            ." PREVISÃO de chegada a {$destination} em {$arrival->format('d/M/Y')}."
            ."\n\nON BOARD CONFIRMATION: Container was loaded at {$ship} {$booking->vg} at {$date->format('M/d/Y')} at  {$origin}."
            ." EXPECTED at {$destination} at {$arrival->format('M/d/Y')}.";
    }

    private function notifyFirstConfirmation(Embarque $booking): void
    {
        if ($booking->impor_expor === 'e' && DB::table('fin_expenses_values')->where('ref_embarque', $booking->getKey())->where('billing', 'a')->exists()) {
            $shipper = DB::table('pessoa')->where('pessoa_id', $booking->pessoa_id_cliente)->value('nome');

            Alert::DeparturePa->send("Embarque com despesas do tipo PA / {$shipper}", "É necessário revisar o embarque {$booking->booking}, pois existem despesas do tipo PA");
        }

        if ([(int) $booking->cliente_id, (int) $booking->pessoa_id_importador] === self::DUROLINE) {
            Alert::Momast->send("[DMF] Nova reserva Duroline/Stemco com embarque confirmado: {$booking->booking}", "A reserva {$booking->booking} teve seu embarque confirmado e é necessário gerar o arquivo MOMAST");
        }
    }

    private function deliver(Embarque $booking, Transborda $tracking, int $userId): void
    {
        $copyBls = $booking->impor_expor === 'e' && $booking->tipo_embarque === 'm' && ! $booking->bl_direto;
        $attachments = [];

        if ($copyBls) {
            foreach ($booking->bls as $bl) {
                $bl->forceFill(['status' => Bl::APPROVED])->save();
                $attachments[] = 'docs_emb/'.$this->finalizeBl->store($booking, $bl);
            }
        }

        $this->sent = ($this->send)($booking, $tracking, $userId, $attachments, $copyBls ? ['diego@dmflog.com.br', 'docexp@dmflog.com.br'] : []);
    }
}

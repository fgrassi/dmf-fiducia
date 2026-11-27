<?php

namespace App\Actions\Booking\Creation;

use App\Actions\Booking\BookingLog;
use App\Actions\Booking\SellerRestriction;
use App\Actions\Notices\SendNoticeMail;
use App\Enums\Alert;
use App\Enums\AmsDestination;
use App\Models\Bl;
use App\Models\Container;
use App\Models\Embarque;
use App\Models\Mercadoria;
use App\Models\User;
use DomainException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * booking/update e booking/updateAereo (legado) para a reserva nova: importação e exportação, marítima e aérea.
 *
 * O legado gravava a reserva pela metade assim que o cliente ou a mercadoria eram escolhidos; aqui tudo é gravado de uma vez, em
 * transação, e os e-mails saem depois dela. As validações que só existiam no navegador rodam no servidor.
 *
 * Diferença do legado, por correção: a reserva de exportação só fica pendente de AMS quando o porto de destino é da África do Sul
 * ou dos Emirados. O legado fazia `if ($pais_destino = 25)` (atribuição) e marcava as duas flags em toda exportação marítima.
 */
class CreateBooking
{
    public const BL_DESCRIPTION = '“AS PER LOCAL PORT AUTHORITIES REGULATIONS, OR CUSTOMS AUTHORITIES MAY RELEASE THE CARGO TO NOTIFY PARTY OR CONSIGNEE AT DESTINATION WITHOUT PRODUCTION OF ORIGINAL BILL/S OF LADING AND NOT INFORMING THE AGENCIES, WE ARE NOT BE RESPONSIBLE FOR SUCH DELIVERY, AND DO NOT ACCEPT ANY LIABILITY”';

    /** Chaves do formulário que não são colunas de embarque. */
    public const NOT_COLUMNS = ['contacts', 'goods', 'instrucao_especial', 'justificativa_seguro', 'pais_id'];

    /**
     * Em branco, o legado grava '' e não nulo nestas colunas. Há filtros (vários em crons que seguem no legado) que deixariam a
     * reserva com nulo de fora: `loading_type <> 'f'`, `insurance <> 's'`, `house_bl not like`, `ce_mercante = ''` e `master_bl is not null`.
     */
    public const BLANK_AS_EMPTY = ['loading_type', 'insurance', 'house_bl', 'master_bl', 'ce_mercante'];

    public function __construct(
        private BookingParties $parties,
        private BookingLog $log,
        private SellerRestriction $sellerRestriction,
        private ImportExpenseMatrix $matrix,
        private CreateInsuranceBooking $insurance,
        private HistorySnapshot $history,
        private BookingSnapshot $snapshot,
        private NextBookingNumber $numbers,
        private SendNoticeMail $send,
    ) {}

    /**
     * @param  array<string, mixed>  $data  dados do formulário (colunas de embarque + contacts, goods, instrucao_especial, justificativa_seguro)
     * @param  'i'|'e'  $operation
     * @param  'm'|'a'  $mode
     *
     * @throws DomainException
     */
    public function __invoke(array $data, string $operation, string $mode, User $user): Embarque
    {
        $this->validate($data, $operation, $mode, $user);

        return DB::transaction(function () use ($data, $operation, $mode, $user) {
            $booking = $this->save($data, $operation, $mode, $user->getKey());
            $this->saveContacts($booking, $data, $operation);
            $this->saveGoods($booking, $data['goods'] ?? []);
            $this->log->track($booking->getKey(), 'Atualizado: Reserva', ($this->snapshot)($booking), $user->getKey(), 'u');

            if ($mode === 'm') {
                $this->afterMaritime($booking, $data, $user);
            }

            // O legado só registrava a justificativa no marítimo; o aéreo também a exige, então registra igual.
            if ($this->needsInsuranceJustification($data, $operation, $mode)) {
                $this->justifyInsurance($booking, $data['justificativa_seguro'], $user);
            }

            ($this->sellerRestriction)($booking);
            ($this->history)($booking->getKey(), $user->getKey());

            if ($booking->cotacao_expo_id) {
                // CotacaoExpo::syncStatusFromEmbarques: cotação com reserva fica "Associada".
                DB::table('cotacao_expo')->where('cotacao_expo_id', $booking->cotacao_expo_id)->update(['status' => 1]);
            }

            return $booking;
        });
    }

    /**
     * O cliente tem seguro obrigatório e a reserva não contrata: precisa justificar. Na edição (`$current`), a reserva que já
     * ficou sem seguro (ctr_seguro = -1) não pede de novo. O aéreo nunca marca essa coluna (o legado pedia a cada edição e não
     * registrava): só pede quando o seguro ou o cliente mudam.
     */
    public function needsInsuranceJustification(array $data, string $operation, string $mode, ?Embarque $current = null): bool
    {
        $asked = $mode === 'a' || $operation === 'i';

        if ($current && $mode === 'a' && ($data['insurance'] ?? null) === $current->insurance
            && (int) ($data['cliente_id'] ?? 0) === (int) $current->cliente_id) {
            return false;
        }

        return $asked
            && (int) $current?->ctr_seguro !== -1
            && ($data['insurance'] ?? null) !== 's'
            && DB::table('pessoa')->where('pessoa_id', $data['cliente_id'] ?? null)->value('seguro_obrigatorio') === 's';
    }

    /**
     * As validações da reserva, na criação e na edição. Na edição (`$current`), o que não mudou não é revalidado: número da
     * importação, blacklist das partes e temperatura das mercadorias que já existiam.
     *
     * @throws DomainException
     */
    public function validate(array $data, string $operation, string $mode, User $user, ?Embarque $current = null): void
    {
        throw_unless(in_array($operation, ['i', 'e'], true) && in_array($mode, ['m', 'a'], true), DomainException::class, 'Tipo de reserva inválido.');
        throw_if($user->onlyOperation() && $user->onlyOperation() !== $operation, DomainException::class, 'Seu usuário não pode gravar reservas desta operação.');

        $maritimeExport = $operation === 'e' && $mode === 'm';

        $required = [
            'vendedor_id' => 'É necessário informar o Vendedor.',
            'data_topo' => 'É necessário informar a Data de solicitação.',
            'booking' => 'O número da reserva não pode estar em branco.',
            'cliente_id' => 'É necessário informar o Cliente.',
            'porto_origem_id' => 'É necessário informar a Origem.',
            'porto_destino_id' => 'É necessário informar o Destino.',
            ...($mode === 'm' ? ['pessoa_id_representante' => 'É necessário informar o Agente.'] : []),
            ...($mode === 'a' || $maritimeExport ? ['de' => 'É necessário informar o Responsável.'] : []),
            // No marítimo o legado deixa passar em branco: a edição não obriga a responder o que a reserva nunca respondeu.
            ...(($mode === 'a' || $operation === 'i') && ! ($current && $mode === 'm' && blank($current->insurance))
                ? ['insurance' => 'Informe se haverá ou não contratação de seguro.']
                : []),
            ...($maritimeExport ? [
                'tipo_fechamento' => 'O tipo de fechamento não pode estar em branco.',
                'armador_id' => 'É necessário informar o Armador.',
                'via' => 'É necessário informar a Via.',
                'free_time_detention' => 'É necessário informar o Free Time Detention (origem).',
                'data_validade' => 'É necessário informar a Data de Validade.',
            ] : []),
            ...($maritimeExport && (int) ($data['flag_fi'] ?? 0) === 1 ? [
                'pessoa_id_pagador' => 'O campo Pagador não pode estar em branco.',
                'recebedor_freight_invoice' => 'O campo Recebedor não pode estar em branco.',
            ] : []),
        ];

        foreach ($required as $field => $message) {
            throw_if(blank(trim((string) ($data[$field] ?? ''))), DomainException::class, $message);
        }

        // Na importação o legado só bloqueia o campo na tela; aqui o número tem que ter saído do botão e ainda estar livre.
        // Na edição, o número só é conferido quando muda: há reservas antigas que já dividem o número com outra.
        $sameNumber = $current && trim((string) $data['booking']) === trim((string) $current->booking);

        if ($operation === 'i' && ! $sameNumber) {
            throw_unless($this->numbers->wasIssued((string) $data['booking'], $mode), DomainException::class, 'Gere o número do booking pelo botão "Solicitar número".');
            throw_if($this->otherBookings($current)->where('booking', $data['booking'])->exists(), DomainException::class, 'Este número de reserva já foi utilizado.');
        }

        foreach (BookingParties::PARTIES as [$column, , $label]) {
            throw_if(
                filled($data[$column] ?? null) && (int) $data[$column] !== (int) $current?->{$column} && $this->parties->isBlacklisted($data[$column]),
                DomainException::class,
                "O {$label} selecionado está na blacklist do sistema.",
            );
        }

        $hasContact = collect(['cliente', 'importador', 'exportador'])
            ->contains(fn (string $party) => filled($this->contactsOf($data, $party, $operation, $current)));
        throw_unless($hasContact, DomainException::class, 'É necessário informar pelo menos um contato do Cliente, Consignee ou Shipper.');

        if ($mode === 'm') {
            foreach (['porto_origem_id' => 'origem', 'porto_destino_id' => 'destino'] as $column => $label) {
                $uncode = DB::table('porto')->where('porto_id', $data[$column])->value('uncode');
                throw_if(blank(trim((string) $uncode)), DomainException::class, "O porto de {$label} não possui UN/Code.");
            }
        }

        if ($maritimeExport) {
            // Na edição, só quando uma das duas datas muda: há reservas que já têm o ETD depois da validade.
            $sameDates = $current && collect(['saida_prevista', 'data_validade'])
                ->every(fn (string $column) => substr((string) ($data[$column] ?? ''), 0, 10) === substr((string) $current->{$column}, 0, 10));

            throw_if(
                ! $sameDates && filled($data['saida_prevista'] ?? null) && substr((string) $data['saida_prevista'], 0, 10) > substr((string) $data['data_validade'], 0, 10),
                DomainException::class,
                'A Saída Prevista não pode ser posterior à Data de Validade da cotação.',
            );

            // Deixar de ser consolidada é a outra mudança que pode criar a duplicidade.
            throw_if(
                ! ($sameNumber && $current->consolidado !== 's')
                    && ($data['consolidado'] ?? 'n') === 'n'
                    && $this->otherBookings($current)->where('booking', $data['booking'])->where('finalizado', '<>', 'c')->where('consolidado', 'n')->exists(),
                DomainException::class,
                'Este número de reserva já foi utilizado. Não é possível ter reservas com números duplicados.',
            );
        }

        $saved = $current ? DB::table('mercadoria')->where('embarque_id', $current->getKey())->pluck('tipo_container_id', 'mercadoria_id') : collect();

        foreach ($data['goods'] ?? [] as $good) {
            $unchanged = filled($good['mercadoria_id'] ?? null) && (int) $saved->get($good['mercadoria_id']) === (int) ($good['tipo_container_id'] ?? 0);

            throw_if(
                $mode === 'm' && ! $unchanged && Container::isReefer($good['tipo_container_id'] ?? null) && blank($good['temperatura'] ?? null),
                DomainException::class,
                'É preciso informar a Temperatura para container REEFER.',
            );
        }

        throw_if(
            $this->needsInsuranceJustification($data, $operation, $mode, $current) && blank($data['justificativa_seguro'] ?? null),
            DomainException::class,
            'Cliente com seguro obrigatório: justifique a não contratação.',
        );
    }

    private function otherBookings(?Embarque $current): Builder
    {
        return DB::table('embarque')->when($current, fn (Builder $q) => $q->where('embarque_id', '<>', $current->getKey()));
    }

    private function save(array $data, string $operation, string $mode, int $userId): Embarque
    {
        $columns = array_filter(Arr::except($data, self::NOT_COLUMNS), fn ($value) => filled($value));

        $attributes = [
            ...array_fill_keys(self::BLANK_AS_EMPTY, ''),
            ...$columns,
            'impor_expor' => $operation,
            'tipo_embarque' => $mode,
            'intIdUser' => $userId,
            'dteCadastro' => today()->toDateString(),
            'finalizado' => 'n',
        ];

        // O legado deixa o campo bloqueado: o despachante DMF é sempre o padrão do porto de destino (a exportação marítima não o tem).
        if (! ($operation === 'e' && $mode === 'm')) {
            $attributes['despachante_dmf_id'] = DB::table('porto')->where('porto_id', $columns['porto_destino_id'])->value('despachante_id');
        }

        if ($mode === 'm') {
            $attributes['etd_inicial'] = $columns['saida_prevista'] ?? null;
            $attributes['eta_inicial'] = $columns['chegada_prevista'] ?? null;

            if ((int) ($columns['flag_fi'] ?? 0) !== 1) {
                unset($attributes['pessoa_id_pagador'], $attributes['recebedor_freight_invoice']);
            }

            if ($operation === 'i' && isset($columns['ce_mercante'])) {
                $attributes['data_ce_mercante'] = now()->toDateTimeString();
            }

            if ($operation === 'e') {
                $country = (int) DB::table('porto')->where('porto_id', $columns['porto_destino_id'])->value('ref_pais');

                foreach (AmsDestination::cases() as $destination) {
                    if ($destination->country() === $country) {
                        $attributes[$destination->statusColumn()] = 1;
                    }
                }
            }
        }

        $booking = (new Embarque)->forceFill($attributes);
        $booking->save();

        return $booking->refresh();
    }

    /** Só os contatos da empresa escolhida naquela parte. @return list<int> */
    public function contactsOf(array $data, string $party, string $operation, ?Embarque $current = null): array
    {
        $personId = $data[BookingParties::PARTIES[$party][0]] ?? null;

        return array_values(array_intersect(
            array_map('intval', $data['contacts'][$party] ?? []),
            array_keys($this->parties->contactOptions($personId, $operation, $current, $party)),
        ));
    }

    private function saveContacts(Embarque $booking, array $data, string $operation): void
    {
        foreach (BookingParties::PARTIES as $party => [, $table]) {
            foreach ($this->contactsOf($data, $party, $operation) as $userId) {
                DB::table($table)->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => $userId]);
            }
        }
    }

    private function saveGoods(Embarque $booking, array $goods): void
    {
        foreach ($goods as $good) {
            Mercadoria::create([...array_filter($good, fn ($value) => filled($value)), 'embarque_id' => $booking->getKey()]);
        }
    }

    /** O que o booking/update faz depois de salvar a reserva marítima, na ordem do legado. */
    private function afterMaritime(Embarque $booking, array $data, User $user): void
    {
        $userId = $user->getKey();

        if (filled($data['instrucao_especial'] ?? null)) {
            DB::table('instrucao_especial_embarque')->insert([
                'embarque_id' => $booking->getKey(),
                'instrucao' => $data['instrucao_especial'],
                'created_at' => now()->toDateTimeString(),
                'created_by' => $userId,
            ]);
        }

        ($this->matrix)($booking, $userId);

        if ($booking->impor_expor === 'e' && (int) $booking->bl_direto === 1 && $booking->tipo_frete === 'c') {
            DB::afterCommit(fn () => $this->warnDirectBl($booking));
        }

        if ($booking->impor_expor === 'i') {
            $contracted = $booking->insurance === 's';
            $contracted && ($this->insurance)($booking, $userId);
            $booking->forceFill(['ctr_seguro' => $contracted ? 1 : -1])->save();
        }

        $ship = DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome');
        Bl::create([
            'embarque_id' => $booking->getKey(),
            'status' => Bl::OPEN,
            'vessel' => $ship ? "{$ship} - {$booking->vg}" : null,
            'desc_pkgs' => self::BL_DESCRIPTION,
        ]);

        if (DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('ctr_liberacao_hbl_origem') === 's') {
            DB::table('liberacao_embarque_origem')->insert(['embarque_id' => $booking->getKey(), 'status' => 0]);
        }
    }

    public function justifyInsurance(Embarque $booking, string $justification, User $user): void
    {
        $client = e((string) DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('nome'));
        $who = e($user->nome);

        $this->log->track(
            $booking->getKey(),
            'Atualizado: Seguro Obrigatório',
            '<p>Cliente com Seguro Obrigatório sem Reserva de Seguro.</p>'
                ."<p>O cliente {$client} possui em nosso sistema o controle de Seguro Obrigatório.</p>"
                ."<p>Entretanto, o usuário {$who} informou que não é necessário contratar seguro para esta reserva, conforme justificativa.</p>",
            $user->getKey(),
            'u',
            $justification,
        );

        DB::afterCommit(fn () => Alert::MandatoryInsuranceWaived->enabled() && ($this->send)(
            "[Aviso] Cliente com Seguro Obrigatório sem Reserva de Seguro - {$booking->booking}",
            "<h3>Seguro Obrigatório</h3><p>O cliente <b>{$client}</b> possui em nosso sistema o controle de Seguro Obrigatório.</p>"
                ."<p>Entretanto, o usuário {$who} informou que não é necessário contratar seguro para a reserva <b>".e((string) $booking->booking).'</b> criada no dia '
                .now()->format('d/m/Y \à\s H:i').' conforme justificativa abaixo:</p><p>Justificativa: '.nl2br(e($justification)).'</p>',
            Alert::MandatoryInsuranceWaived->recipients(),
            cc: Alert::MandatoryInsuranceWaived->copies(),
        ));
    }

    /** BookingBlDiretoNotification: BL direto com pagamento do frete em CC. */
    public function warnDirectBl(Embarque $booking): void
    {
        $lines = [
            'Booking' => $booking->booking,
            'Cliente' => $booking->cliente?->nome,
            'Armador' => $booking->armador?->nome,
            'Porto de Destino' => $booking->portoDestino?->nome,
        ];

        Alert::DirectBlCollect->enabled() && ($this->send)(
            "[Aviso] Reserva com BL DIRETO: SIM e Local Pagamento: CC - {$booking->booking}",
            '<p>Gentileza notar que a reserva <strong>'.e((string) $booking->booking).'</strong> atende as condições:</p>'
                .'<ul><li>BL DIRETO: Sim</li><li>Local de Pagamento do Frete: CC</li></ul>'
                .'<p>Solicitamos a revisão deste booking para validação das informações.</p><p><strong>Dados do Booking:</strong></p><ul>'
                .collect($lines)->map(fn ($value, $label) => "<li>{$label}: ".e((string) $value).'</li>')->implode('').'</ul>',
            Alert::DirectBlCollect->recipients(),
            cc: Alert::DirectBlCollect->copies(),
        );
    }
}

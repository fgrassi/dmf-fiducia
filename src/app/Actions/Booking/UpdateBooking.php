<?php

namespace App\Actions\Booking;

use App\Actions\Booking\Creation\BookingParties;
use App\Actions\Booking\Creation\BookingSnapshot;
use App\Actions\Booking\Creation\CreateBooking;
use App\Actions\Booking\Creation\CreateInsuranceBooking;
use App\Actions\Booking\Creation\HistorySnapshot;
use App\Actions\Booking\Creation\ImportExpenseMatrix;
use App\Actions\Booking\ShippingInstruction\AddInstructionFile;
use App\Enums\Alert;
use App\Enums\AmsDestination;
use App\Models\Bl;
use App\Models\CmnFile;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use App\Models\Mercadoria;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * booking/update e booking/updateAereo (legado) para a reserva que já existe, com as regras que só existiam nas telas de edição
 * (justificativa da validade, aviso de apólice) rodando no servidor.
 *
 * Só grava o que mudou: coluna vazia no formulário e já vazia no banco ('', 0 ou nulo) fica como está, e coluna que a tela da
 * reserva não tem não é tocada (o legado zerava).
 *
 * No `update` do legado a cópia "antiga" é feita depois de aplicar os valores novos, então três comparações nunca disparavam.
 * Aqui comparam com a reserva de antes: a data do CE Mercante e o aviso de BL direto em CC (que a edição na linha do legado já
 * faz). O e-mail de ETA alterado após a confirmação continua sem sair: no legado nunca saiu.
 */
class UpdateBooking
{
    /** Chaves do formulário só da edição, além das de CreateBooking::NOT_COLUMNS. */
    private const EDIT_ONLY = ['justificativa_validade', 'arquivo_validade'];

    /** HistEmbarqueTable::camposRelevantes (legado): só a mudança destes gera foto no hist_embarque. */
    private const HISTORY_COLUMNS = ['saida_prevista', 'chegada_prevista', 'saida_efetiva', 'chegada_efetiva', 'navio_id', 'vg'];

    /** Embarque::getFlagSeguro (legado). */
    private const INSURANCE_EXPENSES = [79, 202, 404, 595];

    /** cmn_files do anexo da justificativa da validade. */
    private const VALIDITY_FILE_TYPE = 45;

    private const VALIDITY_SUBJECT = 'Alteração da Data de Validade da Cotação';

    /** Além das chaves estrangeiras, colunas em que a opção em branco do legado grava 0. */
    public const ZERO_IS_BLANK = ['inland', 'ctr_redestinacao'];

    /** Mercadoria: campo que a tela esconde => o campo que o controla. */
    private const HIDDEN_BY = ['temperatura' => 'tipo_container_id', 'carga_perigosa' => 'quimico'];

    /** Mercadoria: no legado a opção em branco destas colunas grava 2 ("não respondido"). */
    public const TWO_IS_BLANK = ['quimico', 'carga_perigosa'];

    public function __construct(
        private CreateBooking $creation,
        private BookingParties $parties,
        private BookingLog $log,
        private SellerRestriction $sellerRestriction,
        private ImportExpenseMatrix $matrix,
        private CreateInsuranceBooking $insurance,
        private HistorySnapshot $history,
        private BookingSnapshot $snapshot,
    ) {}

    /** As quatro telas de edição do legado: importação e exportação, marítima e aérea. Reserva cancelada não se edita. */
    public static function allows(Embarque $booking, User $user): bool
    {
        return in_array($booking->tipo_embarque, ['m', 'a'], true)
            && in_array($booking->impor_expor, ['i', 'e'], true)
            && $booking->finalizado !== ChangeStatus::CANCELLED
            && (! $user->onlyOperation() || $user->onlyOperation() === $booking->impor_expor);
    }

    /** Na exportação marítima, trocar uma data de validade já informada pede justificativa. */
    public static function changesValidity(Embarque $booking, ?string $validity): bool
    {
        return $booking->impor_expor === 'e'
            && $booking->tipo_embarque === 'm'
            && self::day($booking->data_validade) !== ''
            && self::day($validity) !== self::day($booking->data_validade);
    }

    /**
     * @param  array<string, mixed>  $data  dados do formulário (os da criação + justificativa_validade e arquivo_validade)
     *
     * @throws DomainException
     */
    public function __invoke(Embarque $booking, array $data, User $user): Embarque
    {
        $booking = $booking->fresh();

        throw_unless(self::allows($booking, $user), DomainException::class, 'Esta reserva não pode ser editada.');

        [$operation, $mode] = [$booking->impor_expor, $booking->tipo_embarque];
        $this->creation->validate($data, $operation, $mode, $user, $booking);

        $changesDeparture = array_key_exists('saida_prevista', $data) && self::day($data['saida_prevista']) !== self::day($booking->saida_prevista);
        throw_if(
            $changesDeparture && $booking->departureConfirmations()->exists(),
            DomainException::class,
            'Não é possível alterar a Saída Prevista após a confirmação de embarque!',
        );

        $changesValidity = array_key_exists('data_validade', $data) && self::changesValidity($booking, $data['data_validade']);
        throw_if($changesValidity && blank($data['justificativa_validade'] ?? null), DomainException::class, 'É necessário informar uma justificativa para a alteração da data de validade.');

        // O destino é a pasta uploads do tracking, servida pelo PHP do legado: a extensão é conferida no servidor.
        $file = $changesValidity ? ($data['arquivo_validade'] ?? null) : null;
        throw_if(
            $file && ! in_array(strtolower($file->getClientOriginalExtension()), AddInstructionFile::EXTENSIONS, true),
            DomainException::class,
            'Tipo de arquivo não aceito.',
        );

        $needsInsuranceJustification = $this->creation->needsInsuranceJustification($data, $operation, $mode, $booking);

        return DB::transaction(function () use ($booking, $data, $user, $changesValidity, $needsInsuranceJustification) {
            $userId = $user->getKey();
            $before = clone $booking;

            $this->save($booking, $data);
            $this->saveContacts($booking, $before, $data);
            array_key_exists('goods', $data) && $this->saveGoods($booking, $data['goods'] ?? []);
            $this->log->track($booking->getKey(), 'Atualizado: Reserva', ($this->snapshot)($booking), $userId, 'u');

            if ($changesValidity) {
                $this->justifyValidity($booking, $data['justificativa_validade'], $data['arquivo_validade'] ?? null, $userId);
            }

            if ($booking->tipo_embarque === 'm') {
                $this->afterMaritime($booking, $before, $data, $userId);
            }

            if ($needsInsuranceJustification) {
                $this->creation->justifyInsurance($booking, $data['justificativa_seguro'], $user);
            }

            ($this->sellerRestriction)($booking);

            if ($this->changedHistory($booking, $before)) {
                ($this->history)($booking->getKey(), $userId, 'U');
            }

            return $booking;
        });
    }

    private function save(Embarque $booking, array $data): void
    {
        $changes = $this->changes($booking, Arr::except($data, [...CreateBooking::NOT_COLUMNS, ...self::EDIT_ONLY]));
        $export = $booking->impor_expor === 'e';
        $maritime = $booking->tipo_embarque === 'm';

        // O campo é bloqueado e acompanha o porto de destino; porto sem despachante padrão mantém o que a reserva tinha.
        unset($changes['despachante_dmf_id']);
        $destination = $changes['porto_destino_id'] ?? $booking->porto_destino_id;

        if (! ($export && $maritime) && (int) $destination !== (int) $booking->porto_destino_id) {
            $broker = DB::table('porto')->where('porto_id', $destination)->value('despachante_id');
            $broker && $changes['despachante_dmf_id'] = $broker;
        }

        $booking->forceFill($changes);

        if ($maritime) {
            $booking->etd_inicial ??= $booking->saida_prevista ?: null;
            $booking->eta_inicial ??= $booking->chegada_prevista ?: null;

            if (array_key_exists('flag_fi', $data) && (int) $data['flag_fi'] !== 1) {
                $booking->forceFill(['pessoa_id_pagador' => null, 'recebedor_freight_invoice' => null]);
            }

            if (! $export && $booking->isDirty('ce_mercante')) {
                $booking->data_ce_mercante = now()->toDateTimeString();
            }

            if ($export) {
                $country = (int) DB::table('porto')->where('porto_id', $booking->porto_destino_id)->value('ref_pais');

                foreach (AmsDestination::cases() as $ams) {
                    if ($ams->country() === $country && ! $booking->{$ams->statusColumn()}) {
                        $booking->{$ams->statusColumn()} = 1;
                    }
                }
            }
        }

        $booking->save();
        $booking->refresh();
    }

    /**
     * O que gravar: o vazio só é gravado quando a coluna tinha algum valor, e a data ou hora que a tela devolve mais curta que a
     * do banco ("18:00" para "18:00:00", só o dia para uma coluna com hora) não é mudança.
     *
     * @return array<string, mixed>
     */
    private function changes(Model $record, array $columns): array
    {
        $changes = [];

        foreach ($columns as $column => $value) {
            if (filled($value)) {
                if (! $this->isShorterDate($value, $record->getAttribute($column))) {
                    $changes[$column] = $value;
                }
            } elseif (! self::isEmpty($column, $record->getAttribute($column))) {
                $changes[$column] = in_array($column, CreateBooking::BLANK_AS_EMPTY, true) ? '' : null;
            }
        }

        return $changes;
    }

    private function isShorterDate(mixed $value, mixed $stored): bool
    {
        return is_string($value) && is_string($stored)
            && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/D', $value)
            && str_starts_with($stored, $value.(strlen($value) === 10 ? ' ' : ':'));
    }

    /** O que o legado grava quando o campo fica em branco: nulo, '', data zerada, 0 nas chaves e os códigos de "em branco". */
    public static function isEmpty(string $column, mixed $value): bool
    {
        return blank($value)
            || str_starts_with((string) $value, '0000-00-00')
            || ((str_ends_with($column, '_id') || in_array($column, self::ZERO_IS_BLANK, true)) && (string) $value === '0')
            || (in_array($column, self::TWO_IS_BLANK, true) && (string) $value === '2');
    }

    /** Troca os contatos só das partes que a tela tem (a exportação marítima não tem notify). */
    private function saveContacts(Embarque $booking, Embarque $before, array $data): void
    {
        foreach (BookingParties::PARTIES as $party => [, $table]) {
            if (! array_key_exists($party, $data['contacts'] ?? [])) {
                continue;
            }

            $wanted = $this->creation->contactsOf($data, $party, $booking->impor_expor, $before);
            $linked = $this->parties->linkedContacts($booking, $party);

            DB::table($table)->where('embarque_id', $booking->getKey())->whereIn('usuario_id', array_diff($linked, $wanted))->delete();

            foreach (array_diff($wanted, $linked) as $userId) {
                DB::table($table)->insert(['embarque_id' => $booking->getKey(), 'usuario_id' => $userId]);
            }
        }
    }

    /** Atualiza as mercadorias da reserva, inclui as novas e exclui as que saíram da lista. */
    private function saveGoods(Embarque $booking, array $goods): void
    {
        $saved = Mercadoria::query()->where('embarque_id', $booking->getKey())->get()->keyBy->getKey();
        $kept = [];

        foreach ($goods as $good) {
            $item = $saved->get((int) ($good['mercadoria_id'] ?? 0));
            $values = Arr::except($good, ['mercadoria_id']);

            if (! $item) {
                $kept[] = Mercadoria::create([...array_filter($values, fn ($value) => filled($value)), 'embarque_id' => $booking->getKey()])->getKey();

                continue;
            }

            // A tela esconde estes campos conforme outro: só são limpos quando o campo que os controla muda. Há carga perigosa
            // antiga sem "Químico?" marcado, que não pode se perder numa edição.
            foreach (self::HIDDEN_BY as $hidden => $controller) {
                $old = $item->getAttribute($controller);
                $new = $values[$controller] ?? null;
                $bothBlank = self::isEmpty($controller, $old) && self::isEmpty($controller, $new);

                if (array_key_exists($controller, $values) && ! $bothBlank && (string) $new !== (string) $old) {
                    $values += [$hidden => null];
                }
            }

            foreach ($this->changes($item, $values) as $column => $value) {
                $item->{$column} = $value;
            }

            $item->save();
            $kept[] = $item->getKey();
        }

        $saved->except($kept)->each->delete();
    }

    /** O que o booking/update faz depois de salvar a reserva marítima, na ordem do legado. */
    private function afterMaritime(Embarque $booking, Embarque $before, array $data, int $userId): void
    {
        $this->saveSpecialInstruction($booking, $data['instrucao_especial'] ?? null, $userId);

        ($this->matrix)($booking, $userId);

        if ($this->isDirectBlCollect($booking) && ! $this->isDirectBlCollect($before)) {
            DB::afterCommit(fn () => $this->creation->warnDirectBl($booking));
        }

        if ($booking->impor_expor === 'e') {
            $this->warnInsurancePolicy($booking, $before);
        }

        if ($booking->impor_expor === 'i') {
            $flag = (int) $booking->ctr_seguro;

            if ($booking->insurance === 's' && in_array($flag, [0, -1], true)) {
                ($this->insurance)($booking, $userId);
                $booking->forceFill(['ctr_seguro' => 1])->save();
            } elseif ($flag !== 1) {
                $booking->forceFill(['ctr_seguro' => -1])->save();
            }
        }

        $this->syncBls($booking);

        if (DB::table('pessoa')->where('pessoa_id', $booking->cliente_id)->value('ctr_liberacao_hbl_origem') === 's'
            && ! DB::table('liberacao_embarque_origem')->where('embarque_id', $booking->getKey())->exists()) {
            DB::table('liberacao_embarque_origem')->insert(['embarque_id' => $booking->getKey(), 'status' => 0]);
        }
    }

    /** Em branco não apaga a instrução que a reserva já tem. */
    private function saveSpecialInstruction(Embarque $booking, ?string $instruction, int $userId): void
    {
        if (blank($instruction)) {
            return;
        }

        $table = DB::table('instrucao_especial_embarque');
        $current = (clone $table)->where('embarque_id', $booking->getKey())->orderBy('instrucao_especial_embarque_id')->first();
        $values = ['instrucao' => $instruction, 'created_by' => $userId, 'created_at' => now()->toDateTimeString()];

        if (! $current) {
            $table->insert(['embarque_id' => $booking->getKey(), ...$values]);
        } elseif ($current->instrucao !== $instruction) {
            $table->where('instrucao_especial_embarque_id', $current->instrucao_especial_embarque_id)->update($values);
        }
    }

    private function isDirectBlCollect(Embarque $booking): bool
    {
        return $booking->impor_expor === 'e' && (int) $booking->bl_direto === 1 && $booking->tipo_frete === 'c';
    }

    /** O "Vessel" de todos os BLs acompanha o navio e a VG da reserva; sem BL, cria o primeiro. */
    private function syncBls(Embarque $booking): void
    {
        $ship = DB::table('navio')->where('navio_id', $booking->navio_id)->value('nome');
        $vessel = $ship ? "{$ship} - {$booking->vg}" : null;

        if (! $booking->bls()->exists()) {
            Bl::create(['embarque_id' => $booking->getKey(), 'status' => Bl::OPEN, 'vessel' => $vessel, 'desc_pkgs' => CreateBooking::BL_DESCRIPTION]);
        } elseif ($vessel) {
            // Sem navio, o legado gravava " - VG" por cima do que o BL tinha.
            $booking->bls()->update(['vessel' => $vessel]);
        }
    }

    /** gravaJustificativa (legado): rastreamento, arquivo da negociação e e-mail à exportação com os arquivos. */
    private function justifyValidity(Embarque $booking, string $justification, ?UploadedFile $file, int $userId): void
    {
        $this->log->track($booking->getKey(), self::VALIDITY_SUBJECT, e($justification), $userId, 'u');

        if ($file) {
            $this->storeValidityFile($booking, $file, $userId);
        }

        $attachments = $file
            ? DB::table('emb_embarque_files as e')
                ->join('cmn_files as f', 'f.id', '=', 'e.ref_file')
                ->where('e.ref_embarque', $booking->getKey())
                ->where('e.ref_file_type', self::VALIDITY_FILE_TYPE)
                ->pluck('f.name')
                ->all()
            : [];

        DB::afterCommit(function () use ($booking, $justification, $attachments) {
            Alert::QuotationValidityChanged->send(
                self::VALIDITY_SUBJECT." - {$booking->booking} - {$booking->exportador?->nome}",
                'Justificativa: '.nl2br(e($justification)),
                function (Message $mail) use ($attachments) {
                    foreach ($attachments as $name) {
                        if (Storage::disk('tracking_uploads')->exists("docs_emb/{$name}")) {
                            $mail->attachData(Storage::disk('tracking_uploads')->get("docs_emb/{$name}"), $name);
                        }
                    }
                },
            );
        });
    }

    /** addArquivo (legado): data e hora na frente do nome, sem espaços nem caracteres especiais (aqui, também sem pontos). */
    private function storeValidityFile(Embarque $booking, UploadedFile $file, int $userId): void
    {
        $base = preg_replace('/[^A-Za-z0-9-_]/', '', str_replace(' ', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        $name = now()->format('dmyGi')."_{$base}.".strtolower($file->getClientOriginalExtension());
        Storage::disk('tracking_uploads')->putFileAs('docs_emb', $file, $name);

        $stored = (new CmnFile)->forceFill(['uid' => 1, 'name' => $name, 'created_at' => now()->toDateString(), 'created_by' => $userId]);
        $stored->save();
        $stored->forceFill(['uid' => sha1((string) $stored->getKey()), 'subject' => 'Negociação Validade Frete'])->save();

        (new EmbEmbarqueFile)->forceFill(['ref_embarque' => $booking->getKey(), 'ref_file' => $stored->getKey(), 'ref_file_type' => self::VALIDITY_FILE_TYPE])->save();
    }

    /** enviaEmailTrocaEtd e enviaEmailTrocaNavio (legado): com seguro cobrado, a apólice precisa acompanhar. */
    private function warnInsurancePolicy(Embarque $booking, Embarque $before): void
    {
        $reasons = array_filter([
            self::day($before->saida_prevista) !== '' && self::day($booking->saida_prevista) !== '' && self::day($booking->saida_prevista) < self::day($before->saida_prevista)
                ? 'Houve antecipação do ETD' : null,
            $before->navio_id && (int) $before->navio_id !== (int) $booking->navio_id ? 'Houve troca do nome do Navio' : null,
        ]);

        if (! $reasons || ! $booking->expenses()->whereIn('ref_expense', self::INSURANCE_EXPENSES)->where('selling_rate', '>', 0)->exists()) {
            return;
        }

        DB::afterCommit(function () use ($booking, $reasons) {
            foreach ($reasons as $reason) {
                Alert::InsurancePolicyUpdate->send(
                    "AVISO! Atualização de Apólice: {$booking->booking} - {$booking->cliente?->nome}",
                    "{$reason} e o embarque possui seguro contratado. Gentileza atualizar a Apólice da Reserva<br/>".e((string) $booking->booking),
                );
            }
        });
    }

    private function changedHistory(Embarque $booking, Embarque $before): bool
    {
        $value = fn (mixed $v) => str_starts_with((string) $v, '0000-00-00') ? '' : (string) $v;

        return collect(self::HISTORY_COLUMNS)->contains(fn (string $column) => $value($booking->{$column}) !== $value($before->{$column}));
    }

    /** Só a data (Y-m-d); '' quando não há. */
    private static function day(?string $value): string
    {
        return blank($value) || str_starts_with($value, '0000') ? '' : substr($value, 0, 10);
    }
}

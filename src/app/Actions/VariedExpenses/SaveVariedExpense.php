<?php

namespace App\Actions\VariedExpenses;

use App\Models\CmnFile;
use App\Models\Embarque;
use App\Models\EmbEmbarqueFile;
use App\Models\FinDespesasVariadas;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * saveAction (legado): grava a nota variada e, se veio o arquivo da nota, anexa na reserva (docs_emb) como documento de
 * crédito (tipo 35) ou de débito (tipo 27). A nota nova entra em aberto (`status_pagamento` e `status_enviado` = 0); a
 * edição não mexe nos dois.
 *
 * Diferença do legado: ele gravava o vínculo do arquivo mesmo sem reserva escolhida (na edição, com `ref_embarque` vazio).
 * Aqui, sem a reserva escolhida, vale a que tem o mesmo booking; sem nenhuma, não grava o arquivo.
 */
class SaveVariedExpense
{
    /**
     * @param  array{agente_id:int, cmn_currencies_id:int, data_despesa:string, booking:string, hbl:?string, mbl:?string, invoice:?string, shipper:?string, valor:float|string, impor_expor:?string, tipo_nota:?string}  $data
     *
     * @throws DomainException
     */
    public function __invoke(array $data, ?FinDespesasVariadas $expense = null, ?int $bookingId = null, ?UploadedFile $noteFile = null): FinDespesasVariadas
    {
        $bookingId ??= $noteFile ? Embarque::query()->where('booking', $data['booking'])->value('embarque_id') : null;

        if ($noteFile && ! $bookingId) {
            throw new DomainException('Reserva não encontrada para anexar a nota.');
        }

        return DB::transaction(function () use ($data, $expense, $bookingId, $noteFile) {
            $expense ??= new FinDespesasVariadas(['status_pagamento' => 0, 'status_enviado' => 0]);
            $expense->fill($data)->save();

            if ($noteFile) {
                $this->attach($noteFile, $bookingId, $data['tipo_nota'] === '1' ? FinDespesasVariadas::FILE_TYPE_CREDIT : FinDespesasVariadas::FILE_TYPE_DEBIT);
            }

            return $expense;
        });
    }

    private function attach(UploadedFile $noteFile, int $bookingId, int $type): void
    {
        $name = now()->format('dmyGi').'_'.preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', $noteFile->getClientOriginalName()));

        $file = CmnFile::forceCreate(['name' => $name, 'subject' => 'Documento Anexo a Reserva', 'created_at' => now()->toDateString(), 'uid' => 1]);
        $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

        Storage::disk('tracking_uploads')->putFileAs('docs_emb', $noteFile, $name);

        EmbEmbarqueFile::forceCreate(['ref_embarque' => $bookingId, 'ref_file' => $file->getKey(), 'ref_file_type' => $type]);
    }
}

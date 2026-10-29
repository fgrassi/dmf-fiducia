<?php

namespace App\Actions\PaymentOrders;

use App\Models\CmnFile;
use App\Models\EmbEmbarqueFile;
use App\Models\FinPaymentOrder;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * baixaComprovante (legado): anexa o comprovante de pagamento a uma ou mais ordens e dá a baixa nelas (status Paga e data
 * de pagamento). O arquivo vai para uploads/docs_emb e é ligado a cada reserva uma vez só, com o tipo 54.
 */
class AttachPaymentReceipt
{
    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

    private const PAID = 1;

    private const CANCELED = 2;

    /**
     * @param  list<int>  $orderIds
     *
     * @throws DomainException mensagem do legado
     */
    public function __invoke(array $orderIds, string $paymentDate, ?UploadedFile $receipt, int $userId): void
    {
        if ($orderIds === [] || trim($paymentDate) === '') {
            throw new DomainException('Dados inválidos.');
        }

        if (! $receipt || ! $receipt->isValid()) {
            throw new DomainException('É necessário anexar o Comprovante de Pagamento.');
        }

        if (! in_array(strtolower($receipt->getClientOriginalExtension()), self::ALLOWED, true)) {
            throw new DomainException('Formato de arquivo não permitido. Envie um PDF, JPG ou PNG.');
        }

        try {
            $date = CarbonImmutable::parse($paymentDate)->toDateString();
        } catch (Throwable) {
            throw new DomainException('Dados inválidos.');
        }

        $orders = FinPaymentOrder::with('embarque')->whereIn('id', $orderIds)->get();

        if ($orders->count() !== count(array_unique($orderIds))) {
            throw new DomainException('Um ou mais pedidos selecionados não foram encontrados.');
        }

        if ($orders->contains(fn (FinPaymentOrder $order) => (int) $order->status === self::CANCELED)) {
            throw new DomainException('Não é possível dar baixa em pedidos com Status Pagamento "Cancelada". Revise a seleção.');
        }

        $name = 'emb_'.$orders->first()->embarque->booking.'_'.$this->clean($receipt->getClientOriginalName());

        DB::transaction(function () use ($orders, $receipt, $name, $date, $userId) {
            $file = CmnFile::forceCreate([
                'uid' => 1,
                'name' => $name,
                'subject' => 'Comprovante de Pagamento do Armador',
                'created_at' => now()->toDateString(),
                'created_by' => $userId,
            ]);
            $file->forceFill(['uid' => sha1((string) $file->getKey())])->save();

            Storage::disk('tracking_uploads')->putFileAs('docs_emb', $receipt, $name);

            foreach ($orders->pluck('ref_embarque')->unique() as $bookingId) {
                EmbEmbarqueFile::forceCreate(['ref_embarque' => $bookingId, 'ref_file' => $file->getKey(), 'ref_file_type' => EmbEmbarqueFile::PAYMENT_RECEIPT]);
            }

            $orders->each(fn (FinPaymentOrder $order) => $order->forceFill(['status' => self::PAID, 'payment_date' => $date])->save());
        });
    }

    private function clean(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9-._\-]/', '', str_replace(' ', '_', $name));
    }
}
